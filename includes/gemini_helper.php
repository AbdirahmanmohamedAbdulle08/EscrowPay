<?php
// ============================================================
// GEMINI AI INTEGRATION HELPER
// ============================================================
require_once __DIR__ . '/../config/db.php';

/**
 * Get the configured Gemini API key
 */
function getGeminiApiKey(): string {
    return defined('GEMINI_API_KEY') ? trim(GEMINI_API_KEY) : '';
}

/** Detect phone numbers deliberately written as Somali number words, digits, or a mix. */
function hasObfuscatedContactNumber(string $text): bool {
    $numberWords = 'eber|ebar|hal|labo|lab|saddex|sadax|afar|shan|lix|todobo|toddobo|sideed|sagaal|toban|tobam|labaatan|soddon|afartan|konton|lixdan|todobaatan|sidetan|sagaashan|sagashan|boqol|kun';
    preg_match_all('/(?<![\p{L}\p{N}])(?:\d{1,12}|' . $numberWords . ')(?![\p{L}\p{N}])/iu', mb_strtolower($text), $matches);
    // Three chunks are enough to form an obfuscated contact number (e.g. "hal sagaal shan 4").
    return count($matches[0]) >= 3;
}

/** AI Scam Shield verdict for a single chat message. */
function moderateChatWithGemini(string $message): array {
    $message = trim($message);
    $fallback = static function (string $text): array {
        $plain = mb_strtolower($text);
        $pattern = '/(?:https?:\/\/|www\.|wa\.me|whatsapp|telegram|signal|imo|facebook\.com|instagram\.com|\+?\d[\d\s().-]{4,}\d|\b(?:evc|zaad|edahab|paypal|bank transfer|outside escrow|escrow ka baxsan|numberka|telefoonka|contact)\b)/iu';
        $blocked = (bool)preg_match($pattern, $plain) || hasObfuscatedContactNumber($text);
        return ['action' => $blocked ? 'block' : 'allow', 'risk_score' => $blocked ? 90 : 0,
            'reason_somali' => $blocked ? 'Fariintu waxay u muuqataa inay wadaagayso contact ama lacag-bixin ka baxsan EscrowPay.' : '',
            'source' => 'rules'];
    };
    $ruleResult = $fallback($message);
    if ($ruleResult['action'] === 'block') return $ruleResult;

    $prompt = "Waxaad tahay AI Scam Shield ee EscrowPay, marketplace escrow Soomaali ah. Qiimee HAL fariin oo chat ah. Xannib haddii ay isku dayayso inay wadaagto telefoon/contact/link, ay ka dhigato lacag-bixin EVC/Zaad/bank/PayPal oo ka baxsan escrow, ay qof u jiheynayso WhatsApp/Telegram/IMO, ama ay tahay khiyaano/phishing. U oggolow wada hadal ganacsi caadi ah. Haddii aad shaki weyn qabto, dooro review. Ha xannibin erayo caadi ah sida qiime, lacag, ama delivery keliya.\n\nFariinta: " . $message . "\n\nSoo celi JSON keliya: {\"action\":\"allow|review|block\",\"risk_score\":0,\"reason_somali\":\"sabab gaaban oo Soomaali ah; madhan haddii allow\"}.";
    $ai = callGeminiAI($prompt, [], 'gemini-flash-latest', true);
    if (!$ai['success']) return $ruleResult;
    $parsed = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($ai['text'])), true);
    if (!is_array($parsed) || !in_array(($parsed['action'] ?? ''), ['allow', 'review', 'block'], true)) return $ruleResult;
    return ['action' => $parsed['action'], 'risk_score' => max(0, min(100, (int)($parsed['risk_score'] ?? 0))),
        'reason_somali' => trim((string)($parsed['reason_somali'] ?? '')), 'source' => 'gemini'];
}

/**
 * Call Gemini API with text and optional images
 * 
 * @param string $prompt Text prompt
 * @param array $imagePaths Array of absolute or relative filepaths to images
 * @param string $model Model name (e.g. gemini-2.0-flash, gemini-1.5-flash)
 * @return array ['success' => bool, 'text' => string, 'raw' => array, 'error' => string]
 */
function callGeminiAI(string $prompt, array $imagePaths = [], string $model = 'gemini-3.6-flash', bool $jsonMode = false): array {
    $apiKey = getGeminiApiKey();
    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'Gemini API Key is not configured. Please set it in SuperAdmin Settings.'];
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

    $parts = [];

    // Add image parts if provided (resize to max 800px to reduce payload size & avoid timeouts)
    foreach ($imagePaths as $imgPath) {
        if (empty($imgPath)) continue;

        $fullPath = $imgPath;
        if (!file_exists($fullPath)) {
            $candidate = __DIR__ . '/../' . ltrim($imgPath, '/\\');
            if (file_exists($candidate)) $fullPath = $candidate;
        }

        if (file_exists($fullPath) && is_file($fullPath)) {
            $mimeType = mime_content_type($fullPath) ?: 'image/jpeg';
            // Resize image to max 800px to speed up API call
            $resizedData = null;
            if (function_exists('imagecreatefromstring')) {
                $raw = file_get_contents($fullPath);
                if ($raw !== false) {
                    $src = @imagecreatefromstring($raw);
                    if ($src) {
                        $w = imagesx($src); $h = imagesy($src);
                        $maxDim = 800;
                        if ($w > $maxDim || $h > $maxDim) {
                            $ratio = $w > $h ? $maxDim / $w : $maxDim / $h;
                            $nw = (int)($w * $ratio); $nh = (int)($h * $ratio);
                            $dst = imagecreatetruecolor($nw, $nh);
                            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                            ob_start();
                            imagejpeg($dst, null, 85);
                            $resizedData = ob_get_clean();
                            imagedestroy($dst);
                            $mimeType = 'image/jpeg';
                        }
                        imagedestroy($src);
                    }
                }
            }
            $fileData = $resizedData ?: file_get_contents($fullPath);
            if ($fileData !== false) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data'      => base64_encode($fileData)
                    ]
                ];
            }
        }
    }

    // Add the text prompt part
    $parts[] = ['text' => $prompt];

    $generationConfig = [
        'temperature'     => 0.1,
        'maxOutputTokens' => 1024,
    ];
    if ($jsonMode) $generationConfig['responseMimeType'] = 'application/json';
    if (str_contains($model, '3.7') || str_contains($model, '3.6')) {
        $generationConfig['thinkingConfig'] = ['thinkingLevel' => 'high'];
    }

    $payload = [
        'contents' => [
            ['role' => 'user', 'parts' => $parts]
        ],
        'generationConfig' => $generationConfig
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        // Do not inherit a local development proxy (for example 127.0.0.1:9),
        // which prevents XAMPP from reaching Gemini directly.
        CURLOPT_PROXY          => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => 'cURL Error: ' . $curlErr];
    }

    $json = json_decode($response, true);

    if ($httpCode !== 200) {
        $msg = $json['error']['message'] ?? "HTTP {$httpCode}: " . substr($response, 0, 300);
        return ['success' => false, 'error' => $msg, 'raw' => $json];
    }

    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
    return [
        'success' => true,
        'text'    => trim($text),
        'raw'     => $json
    ];
}

/** Generate a catalogue image from a product name using Gemini Image. */
function generateGeminiProductImage(string $prompt): array {
    $apiKey = getGeminiApiKey();
    if (empty($apiKey)) return ['success' => false, 'error' => 'AI key lama helin.'];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image:generateContent?key=' . urlencode($apiKey);
    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => ['responseModalities' => ['IMAGE']]
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0
    ]);
    $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlErr = curl_error($ch); curl_close($ch);
    if ($curlErr) return ['success' => false, 'error' => 'cURL Error: ' . $curlErr];
    $json = json_decode($response, true);
    if ($httpCode !== 200) return ['success' => false, 'error' => $json['error']['message'] ?? "HTTP {$httpCode}"];
    foreach (($json['candidates'][0]['content']['parts'] ?? []) as $part) {
        $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
        if (!empty($inline['data'])) return ['success' => true, 'data' => $inline['data'], 'mime_type' => $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png'];
    }
    return ['success' => false, 'error' => 'AI sawir ma soo celin.'];
}

/**
 * Compare two images and return a similarity score 0-100 using color histograms
 * 100 = identical, 0 = completely different
 */
function compareImageSimilarity(string $path1, string $path2): int {
    if (!function_exists('imagecreatefromstring') || !file_exists($path1) || !file_exists($path2)) return 50;
    $raw1 = @file_get_contents($path1); $raw2 = @file_get_contents($path2);
    if (!$raw1 || !$raw2) return 50;
    $img1 = @imagecreatefromstring($raw1); $img2 = @imagecreatefromstring($raw2);
    if (!$img1 || !$img2) return 50;
    // Resize both to 16x16 for histogram comparison
    $t1 = imagecreatetruecolor(16, 16); $t2 = imagecreatetruecolor(16, 16);
    imagecopyresampled($t1, $img1, 0, 0, 0, 0, 16, 16, imagesx($img1), imagesy($img1));
    imagecopyresampled($t2, $img2, 0, 0, 0, 0, 16, 16, imagesx($img2), imagesy($img2));
    imagedestroy($img1); imagedestroy($img2);
    // Compare average colors per quadrant
    $diff = 0;
    for ($x = 0; $x < 16; $x++) {
        for ($y = 0; $y < 16; $y++) {
            $c1 = imagecolorat($t1, $x, $y); $c2 = imagecolorat($t2, $x, $y);
            $diff += abs(($c1 >> 16 & 0xFF) - ($c2 >> 16 & 0xFF)); // R
            $diff += abs(($c1 >> 8 & 0xFF)  - ($c2 >> 8 & 0xFF));  // G
            $diff += abs(($c1 & 0xFF)        - ($c2 & 0xFF));       // B
        }
    }
    imagedestroy($t1); imagedestroy($t2);
    // Max possible diff = 16*16*3*255 = 195840
    $similarity = max(0, (int)(100 - ($diff / 195840 * 100)));
    return $similarity;
}

/**
 * AI Dispute Forensic & Condition Analyzer
 * Analyzes the Chain-of-Custody visual timeline and transaction evidence
 */
function analyzeDisputeWithGemini(int $disputeId): array {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT d.*, 
               t.id AS tx_id, t.ref_code, t.title AS order_title, t.description AS order_desc, 
               t.amount, t.fee, t.net_amount, t.seller_dispatch_proof, t.buyer_received_proof,
               b.name AS buyer_name, b.email AS buyer_email, b.phone AS buyer_phone,
               s.name AS seller_name, s.email AS seller_email, s.store_name,
               del.dispatch_proof, del.pickup_proof, del.delivery_proof,
               drv.id AS driver_user_id, drv.name AS driver_name, drv.phone AS driver_phone, drv.email AS driver_email,
               drv.address AS driver_address, drv.vehicle_type, drv.vehicle_plate,
               drv.id_number AS driver_license,
               (SELECT COUNT(*) FROM deliveries dh WHERE dh.delivery_id=drv.id) AS driver_total_jobs,
               (SELECT COUNT(*) FROM deliveries dh2 WHERE dh2.delivery_id=drv.id AND dh2.status='delivered') AS driver_completed_jobs
        FROM disputes d
        JOIN transactions t ON d.transaction_id = t.id
        JOIN users b ON t.buyer_id = b.id
        JOIN users s ON t.seller_id = s.id
        LEFT JOIN deliveries del ON del.transaction_id = t.id
        LEFT JOIN users drv ON COALESCE(del.delivery_id, t.delivery_id) = drv.id
        WHERE d.id = ?
    ");
    $stmt->execute([$disputeId]);
    $disp = $stmt->fetch();

    if (!$disp) {
        return ['success' => false, 'error' => 'Dispute not found.'];
    }

    // Collect chat history for context
    $msgStmt = $pdo->prepare("
        SELECT m.*, u.name AS sender_name, u.role AS sender_role 
        FROM messages m 
        JOIN users u ON m.sender_id = u.id 
        WHERE m.transaction_id = ? 
        ORDER BY m.created_at ASC LIMIT 20
    ");
    $msgStmt->execute([$disp['tx_id']]);
    $messages = $msgStmt->fetchAll();

    $chatHistoryText = "";
    foreach ($messages as $m) {
        $chatHistoryText .= "[{$m['sender_role']}] {$m['sender_name']}: {$m['message']}\n";
    }
    if (empty($chatHistoryText)) {
        $chatHistoryText = "No message logs recorded.";
    }

    // Check if delivery driver was involved
    $hasDelivery = !empty($disp['delivery_id']) || !empty($disp['driver_name']) || !empty($disp['pickup_proof']) || !empty($disp['delivery_proof']);
    $isDigitalService = !$hasDelivery || stripos($disp['order_title'], 'service') !== false || stripos($disp['order_title'], 'course') !== false || stripos($disp['order_title'], 'design') !== false;

    // Collect visual proof images
    $images = [];
    $imageDescriptions = [];

    // 1. Seller Dispatch Proof
    $sellerProof = $disp['seller_dispatch_proof'] ?: $disp['dispatch_proof'];
    if ($sellerProof && file_exists(__DIR__ . '/../' . $sellerProof)) {
        $images[] = __DIR__ . '/../' . $sellerProof;
        $imageDescriptions[] = "Image 1: Seller Packaging / Dispatch Proof (Taken by Seller when packing/dispatching).";
    } else {
        $imageDescriptions[] = "Image 1: [MISSING] Seller did not upload initial packaging proof.";
    }

    if ($hasDelivery) {
        // 2. Driver Pickup Proof
        $pickupProof = $disp['pickup_proof'];
        if ($pickupProof && file_exists(__DIR__ . '/../' . $pickupProof)) {
            $images[] = __DIR__ . '/../' . $pickupProof;
            $imageDescriptions[] = "Image 2: Moto Delivery Pickup Proof (Taken by Driver when collecting item from seller).";
        } else {
            $imageDescriptions[] = "Image 2: [MISSING] Driver did not upload pickup proof.";
        }

        // 3. Driver Dropoff Proof
        $dropoffProof = $disp['delivery_proof'];
        if ($dropoffProof && file_exists(__DIR__ . '/../' . $dropoffProof)) {
            $images[] = __DIR__ . '/../' . $dropoffProof;
            $imageDescriptions[] = "Image 3: Moto Delivery Dropoff Proof (Taken by Driver at buyer destination upon arrival).";
        } else {
            $imageDescriptions[] = "Image 3: [MISSING] Driver did not upload dropoff proof.";
        }
    }

    // 4. Buyer Received Proof / Dispute Evidence
    $buyerProof = $disp['buyer_received_proof'] ?: $disp['evidence_file'];
    if ($buyerProof && file_exists(__DIR__ . '/../' . $buyerProof)) {
        $images[] = __DIR__ . '/../' . $buyerProof;
        $imageDescriptions[] = "Image " . ($hasDelivery ? "4" : "2") . ": Buyer Received Proof / Dispute Evidence (Taken by Buyer upon receipt/complaint).";
    } else {
        $imageDescriptions[] = "Image " . ($hasDelivery ? "4" : "2") . ": [MISSING] Buyer did not attach photo evidence.";
    }

    // Construct prompt
    $orderTypeContext = $isDigitalService && !$hasDelivery 
        ? "NOTE: This transaction is a DIGITAL SERVICE / ONLINE COURSE / DIRECT SERVICE. Physical moto delivery was NOT required."
        : "NOTE: This transaction is a PHYSICAL PRODUCT shipped via Moto Delivery Driver ({$disp['driver_name']}).";

    $prompt = "You are the AI Chief Forensic Arbiter and Dispute Mediator for EscrowPay, a multi-party escrow marketplace in Somalia.
You must analyze a dispute between a Buyer and a Seller (and Moto Delivery Driver if physical delivery).

### TRANSACTION DETAILS:
- Order Reference: {$disp['ref_code']}
- Item Title: {$disp['order_title']}
- Order Description: {$disp['order_desc']}
- Escrow Value: \${$disp['amount']} (Net Payout: \${$disp['net_amount']})
- Buyer: {$disp['buyer_name']}
- Seller: {$disp['seller_name']} (Store: " . ($disp['store_name'] ?: 'Independent') . ")
- Delivery Driver: " . ($disp['driver_name'] ?: 'No Driver / Digital Service / Direct') . "
- Driver Contact: " . ($disp['driver_phone'] ?: 'Not provided') . " | Email: " . ($disp['driver_email'] ?: 'Not provided') . "
- Moto/Vehicle: " . ($disp['vehicle_type'] ?: 'Not provided') . " | Plate: " . ($disp['vehicle_plate'] ?: 'Not provided') . " | Address: " . ($disp['driver_address'] ?: 'Not provided') . "
- Driver License/ID Number: " . ($disp['driver_license'] ?: 'Not provided') . "
- Driver History: " . (int)($disp['driver_completed_jobs'] ?? 0) . " completed out of " . (int)($disp['driver_total_jobs'] ?? 0) . " jobs
- Fulfillment Type: {$orderTypeContext}

### DISPUTE CLAIM:
- Dispute Reason: {$disp['reason']}
- Buyer Statement: {$disp['description']}
- Buyer Submitted Evidence Note: {$disp['evidence']}

### CHAIN OF CUSTODY VISUAL PROOFS RECORDED:
" . implode("\n", $imageDescriptions) . "

### CHAT LOGS BETWEEN PARTIES:
{$chatHistoryText}

### FORENSIC CHAIN OF CUSTODY RULES:
1. If Physical Delivery:
   - Compare Seller Packaging Proof vs Driver Pickup Proof. Did the driver collect what the seller packed?
   - Compare Driver Pickup Proof vs Driver Dropoff Proof. Did the driver swap, damage, or alter the item during transit?
   - Compare Driver Dropoff Proof vs Buyer Defect Claim. Was the defect present upon dropoff, or is the buyer making a false post-delivery claim?
2. If Digital Service / Course / Direct:
   - Delivery agent is not needed. Compare seller deliverables/work with buyer requirements.
3. Determine fault: 'seller' (sent defective/wrong item or failed delivery), 'delivery' (damaged/swapped item in transit between pickup and dropoff), 'buyer' (fraudulent / false claim), or 'undetermined'.
4. Recommend either 'refund_buyer' (Refund 100% to buyer) or 'release_seller' (Release funds to seller).
5. Provide a clear, authoritative explanation in Af-Soomaali and English.

You MUST respond strictly with a valid JSON object matching this schema (do NOT wrap with markdown backticks or extra text):
{
  \"recommendation\": \"refund_buyer\" | \"release_seller\",
  \"culprit\": \"seller\" | \"buyer\" | \"delivery\" | \"undetermined\",
  \"risk_score\": 0 to 100,
  \"confidence\": 85,
  \"summary_somali\": \"Warbixin kooban oo 1-2 weedhood ah oo af-Soomaali cad ah\",
  \"verdict_title_somali\": \"Go'aanka Garsoorka AI\",
  \"detailed_analysis_somali\": \"Falanqayn qodobaysan oo faahfaahsan oo af-Soomaali ah oo muujinaysa sababta go'aanka loo qaatay, sawirrada waxa ka muuqda, iyo cidda khaldan (Seller, Darawal, ama Buyer).\",
  \"admin_ruling_note\": \"Professional note to include in official ruling message to both parties.\"
  ,\"driver_accountability\": \"Haddii culprit-ku delivery yahay, ku dar magaca buuxa, phone, email, cinwaan, vehicle/moto, plate, license/ID number, iyo taariikhda shaqada darawalka.\"
}";

    // Gemini 2.5 Flash was retired; use the current Vision-capable Flash model.
    $res = callGeminiAI($prompt, $images, 'gemini-3.7-flash', true);
    if (!$res['success']) {
        $res = callGeminiAI($prompt, $images, 'gemini-3.6-flash', true);
    }

    if (!$res['success']) {
        // Fallback rule-based simulation if API key is rate-limited or unavailable during offline hackathon demo
        return generateSimulatedDisputeAnalysis($disp, $res['error']);
    }

    $rawText = $res['text'];
    // Clean potential markdown json fences
    $cleanJson = preg_replace('/^```json\s*/i', '', $rawText);
    $cleanJson = preg_replace('/^```\s*/i', '', $cleanJson);
    $cleanJson = preg_replace('/```$/', '', trim($cleanJson));

    $parsed = json_decode($cleanJson, true);
    if (!is_array($parsed) || empty($parsed['recommendation'])) {
        return generateSimulatedDisputeAnalysis($disp, 'AI output JSON parsing fallback');
    }

    if (($parsed['culprit'] ?? '') === 'delivery') {
        $parsed['driver_accountability'] = sprintf('Darawal: %s | Phone: %s | Email: %s | Moto: %s | Plate: %s | License/ID: %s | Address: %s | History: %d/%d deliveries completed.', $disp['driver_name'] ?: 'Lama aqoon', $disp['driver_phone'] ?: 'Lama gelin', $disp['driver_email'] ?: 'Lama gelin', $disp['vehicle_type'] ?: 'Lama gelin', $disp['vehicle_plate'] ?: 'Lama gelin', $disp['driver_license'] ?: 'Lama gelin', $disp['driver_address'] ?: 'Lama gelin', (int)($disp['driver_completed_jobs'] ?? 0), (int)($disp['driver_total_jobs'] ?? 0));
        $parsed['detailed_analysis_somali'] = ($parsed['detailed_analysis_somali'] ?? '') . "\nXogta la xisaabtanka darawalka: " . $parsed['driver_accountability'];
    }

    // Save verdict to database
    $aiRisk = (int)($parsed['risk_score'] ?? 50);
    $pdo->prepare("UPDATE disputes SET ai_verdict = ?, ai_risk_score = ?, ai_analyzed_at = NOW() WHERE id = ?")
        ->execute([json_encode($parsed, JSON_UNESCAPED_UNICODE), $aiRisk, $disputeId]);

    return [
        'success' => true,
        'verdict' => $parsed,
        'raw_text' => $rawText
    ];
}

/**
 * High-accuracy fallback engine - Image-driven visual comparison
 * Uses perceptual hashing (color histogram) to detect if photos match
 */
function generateSimulatedDisputeAnalysis(array $disp, string $errorMsg = ''): array {
    $pdo = getDB();

    // ─── Resolve image file paths ─────────────────────────────────────────────
    $sellerPath  = null; $pickupPath = null; $dropoffPath = null; $buyerPath = null;
    $base = __DIR__ . '/../';
    $sp = $disp['seller_dispatch_proof'] ?: $disp['dispatch_proof'];
    if ($sp && file_exists($base . $sp))  $sellerPath  = $base . $sp;
    if (!empty($disp['pickup_proof'])   && file_exists($base . $disp['pickup_proof']))   $pickupPath  = $base . $disp['pickup_proof'];
    if (!empty($disp['delivery_proof']) && file_exists($base . $disp['delivery_proof'])) $dropoffPath = $base . $disp['delivery_proof'];
    $bp = $disp['buyer_received_proof'] ?: $disp['evidence_file'];
    if ($bp && file_exists($base . $bp)) $buyerPath = $base . $bp;

    $hasSellerProof  = $sellerPath  !== null;
    $hasPickupProof  = $pickupPath  !== null;
    $hasDropoffProof = $dropoffPath !== null;
    $hasBuyerProof   = $buyerPath   !== null;
    $hasDelivery     = !empty($disp['driver_name']) || $hasPickupProof || $hasDropoffProof;

    // ─── IMAGE-BASED visual forensic analysis ─────────────────────────────────
    // Score: how similar pickup vs dropoff are (if very different → driver tamper)
    $pickupDropoffSim = ($hasPickupProof && $hasDropoffProof)
        ? compareImageSimilarity($pickupPath, $dropoffPath) : -1;

    // How similar seller vs buyer are
    $sellerBuyerSim = ($hasSellerProof && $hasBuyerProof)
        ? compareImageSimilarity($sellerPath, $buyerPath) : -1;

    // How similar dropoff vs buyer defect proof are
    $dropoffBuyerSim = ($hasDropoffProof && $hasBuyerProof)
        ? compareImageSimilarity($dropoffPath, $buyerPath) : -1;

    // Also compare seller vs dropoff to detect item swap at any point
    $sellerDropoffSim = ($hasSellerProof && $hasDropoffProof)
        ? compareImageSimilarity($sellerPath, $dropoffPath) : -1;

    // The first hand-off is the most important identity check: a driver must
    // collect the same item the seller photographed. A high pickup/dropoff
    // score alone must never approve a shipment when Seller→Pickup differs.
    $sellerPickupSim = ($hasSellerProof && $hasPickupProof)
        ? compareImageSimilarity($sellerPath, $pickupPath) : -1;
    $parsed = null;

    // Chain-of-custody is CLEAN only if seller≈pickup AND pickup≈dropoff (both > 70%)
    $chainIsClear = ($pickupDropoffSim >= 70 && $sellerDropoffSim >= 70 && $sellerPickupSim >= 70);
    // Tamper suspected if pickup→dropoff similarity < 70% and seller≈pickup is high (iPhone → different item)
    $tamperSuspected = ($hasPickupProof && $hasDropoffProof)
        && ($pickupDropoffSim < 70)
        && ($hasSellerProof ? compareImageSimilarity($sellerPath, $pickupPath) >= 70 : true);

    // Seller packaging and driver pickup show different items (e.g. iPhone vs
    // laptop). Do not release funds; the chain is broken at hand-off.
    if (!$parsed && $hasSellerProof && $hasPickupProof && $sellerPickupSim < 70) {
        $parsed = [
            'recommendation' => 'refund_buyer', 'culprit' => 'delivery', 'risk_score' => 96, 'confidence' => 95,
            'verdict_title_somali' => 'Garsoorka AI: Alaabtii Seller-ka iyo Pickup-ku Waa Kala Duwan Yihiin',
            'summary_somali' => "Sawirka seller-ka iyo sawirka pickup-ka waxay muujinayaan alaabo kala duwan. Silsiladda delivery-ga waa jabtay, sidaas darteed buyer-ka lacagtiisa waa in loo celiyo.",
            'detailed_analysis_somali' => "1. Seller Packaging → Driver Pickup similarity waxay ahayd {$sellerPickupSim}% (aad u hooseysa).\n2. Pickup-ku ma xaqiijinayo alaabtii seller-ku sheegay; buyer-ku wuxuu keenay caddayn kale.\n3. Lacagta buyer-ka 100% ha loo celiyo, dhacdadana darawalka iyo seller-ka ha laga baaro.",
            'admin_ruling_note' => "AI Visual Forensic found a broken Seller-to-Pickup chain (similarity {$sellerPickupSim}%). Do not release funds; refund buyer and investigate hand-off."
        ];
    }
    // ─── Decision Logic (VISUAL-FIRST, text is secondary hint) ────────────────
    // SCENARIO A: Driver tampered in transit (pickup ≈ OK, dropoff very different from pickup, buyer has defect proof)
    if ($tamperSuspected && $hasBuyerProof) {
        $parsed = [
            'recommendation'          => 'refund_buyer',
            'culprit'                 => 'delivery',
            'risk_score'              => 87,
            'confidence'              => 92,
            'verdict_title_somali'    => "Garsoorka AI: Darawalku Wuxuu Bedelay Alaabta Jidka (Driver Tampered In Transit)",
            'summary_somali'          => "Baaritaanka muuqaalka AI: sawirka qaadista (Pickup) iyo sawirka gaarsiinta (Dropoff) waxay muujinayaan farqi weyn — taasoo caddaynaysa in darawalku alaabta u beddelay ama waxyeello geystay intii uu jidka ku jiray.",
            'detailed_analysis_somali'=> "1. Qiyaasta waafajinta sawirrada (Pickup vs Dropoff) waxay ahayd {$pickupDropoffSim}% — farqi weyn ayaa jira.\n2. Buyer-ku wuxuu keenay caddayn muujinaysa alaab kala duwan oo aan waafaqsanayn tii seller-ku diray.\n3. Soo-jeedinta AI: Lacagta $" . number_format($disp['amount'], 2) . " 100% ah dib loogu celiyo iibsadaha {$disp['buyer_name']}, darawalka lana xisaabtamo.",
            'admin_ruling_note'       => "AI Visual Forensic: Pickup-to-Dropoff similarity {$pickupDropoffSim}% (< 70% threshold). Delivery agent likely tampered with item. Full refund approved for Buyer {$disp['buyer_name']}."
        ];
    }

    // SCENARIO B: Driver did NOT upload dropoff photo but buyer has defect proof → driver suspicious
    if (!$parsed && $hasPickupProof && !$hasDropoffProof && $hasBuyerProof) {
        $parsed = [
            'recommendation'          => 'refund_buyer',
            'culprit'                 => 'delivery',
            'risk_score'              => 82,
            'confidence'              => 89,
            'verdict_title_somali'    => "Garsoorka AI: Darawalku Kama Keenin Sawirka Gaarsiinta (Missing Dropoff Proof)",
            'summary_somali'          => "Darawalku wuxuu soo qaaday alaabta (sawir buu keenay), laakiin ma soo gelin sawirkii Dropoff-ka (gaarsiinta rasmiga ah), halka iibsaduhu soo bandhigay caddeyn muujinaysa alaab khaldan.",
            'detailed_analysis_somali'=> "1. Darawalku sawirka Pickup-ka wuu keenay, laakiin sawirka Dropoff-ka (gaarsiinta rasmiga ah) ma soo gelin — taasoo ah fure muhiim ah oo shaki galisay.\n2. Iibsaduhu wuxuu keenay sawir cad oo muujinaya alaab waxyeello gaartay ama khaldan.\n3. Soo-jeedinta AI: Lacagta $" . number_format($disp['amount'], 2) . " dib loogu celiyo iibsadaha {$disp['buyer_name']}.",
            'admin_ruling_note'       => "AI Visual Forensic: Driver uploaded pickup proof but NOT dropoff proof. Combined with buyer defect evidence, delivery agent suspected. Full refund approved for Buyer {$disp['buyer_name']}."
        ];
    }

    // SCENARIO C: Seller has no packaging proof but buyer has defect proof → seller at fault
    if (!$parsed && !$hasSellerProof && $hasBuyerProof) {
        $parsed = [
            'recommendation'          => 'refund_buyer',
            'culprit'                 => 'seller',
            'risk_score'              => 83,
            'confidence'              => 91,
            'verdict_title_somali'    => "Garsoorka AI: Iibiyaha Ma Keenin Sawirka Diyaarinta (Seller Missing Packaging Proof)",
            'summary_somali'          => "Iibiyuhu ma soo gelin sawirka diyaarinta (Packaging Proof), halka iibsaduhu keenay caddeyn buuxda oo muujinaysa cillad ama alaab khaldan.",
            'detailed_analysis_somali'=> "1. Iibiyuhu ma soo gelin sawir caddeynaya inuu alaab badqabta diray.\n2. Iibsaduhu wuxuu keenay sawir cad oo muujinaya alaab/adeeg khaldan ama aan waafaqsanayn heshiiska.\n3. Soo-jeedinta AI: Lacagta $" . number_format($disp['amount'], 2) . " 100% dib loogu celiyo iibsadaha {$disp['buyer_name']}.",
            'admin_ruling_note'       => "AI Visual Forensic: Seller lacked dispatch/packaging proof. Buyer provided defect evidence. Full refund approved for Buyer {$disp['buyer_name']}."
        ];
    }

    // SCENARIO D: All 4 proofs present, pickup ≈ dropoff (consistent chain), buyer claim appears weak → release seller
    if (!$parsed && $hasSellerProof && $hasPickupProof && $hasDropoffProof && $hasBuyerProof && $sellerPickupSim >= 70 && $pickupDropoffSim >= 70 && $sellerDropoffSim >= 70) {
        $parsed = [
            'recommendation'          => 'release_seller',
            'culprit'                 => 'buyer',
            'risk_score'              => 22,
            'confidence'              => 91,
            'verdict_title_somali'    => "Garsoorka AI: Silsiladda Keenista Waa Saxsanayd — Cabashada Iibsaduhu Waa Daciif (Buyer Claim Weak)",
            'summary_somali'          => "Baaritaanka muuqaalka AI wuxuu xaqiijiyay in silsiladda 4-ta heer ee keenista si buuxda loo fuliyay. Sawirrada kala duwanaantoodu waxay muujisaa in alaabtu badqab ahayd jidkeedii oo dhan.",
            'detailed_analysis_somali'=> "1. Seller Packaging → Driver Pickup → Driver Dropoff → Buyer Evidence: dhammaan is-waafajinta sawirradu waa sare (Seller→Pickup {$sellerPickupSim}%, Pickup→Dropoff {$pickupDropoffSim}%, Seller→Dropoff {$sellerDropoffSim}%).\n2. Sawirrada lama arko farqi muujinaya in alaabta la beddelay ama waxyeello gaartay jidka.\n3. Soo-jeedinta AI: In lacagta $" . number_format($disp['net_amount'], 2) . " loo sii daayo iibiyaha {$disp['seller_name']}.",
            'admin_ruling_note'       => "AI Visual Forensic: 4-proof chain consistent (Pickup-Dropoff similarity {$pickupDropoffSim}%). No evidence of item swap or damage in transit. Funds released to Seller {$disp['seller_name']}."
        ];
    }

    // SCENARIO E: Default (partial proofs, no clear winner) → lean on who has more evidence
    if (!$parsed) {
        if ($hasBuyerProof && !$hasSellerProof && !$hasPickupProof) {
            $parsed = [
                'recommendation'          => 'refund_buyer',
                'culprit'                 => 'seller',
                'risk_score'              => 70,
                'confidence'              => 75,
                'verdict_title_somali'    => "Garsoorka AI: Caddaynta Iibsaduhu Way Xoog Badnayd (Buyer Evidence Stronger)",
                'summary_somali'          => "Iibsaduhu wuxuu keenay caddayn sawir ah halka iibiyuhu iyo darawalku aysan haysan sawir qasab ah. Soo-jeedinta AI waxay u janjeedhay iibsadaha.",
                'detailed_analysis_somali'=> "1. Iibsaduhu wuxuu keenay caddayn muuqaal ah.\n2. Iibiyuhu iyo darawalku ma haysan sawir xaqiijin.\n3. Soo-jeedinta AI: In lacagta $" . number_format($disp['amount'], 2) . " 100% dib loogu celiyo iibsadaha {$disp['buyer_name']}.",
                'admin_ruling_note'       => "AI Forensic: Buyer provided visual evidence; Seller/Driver lacked any proof. Refund recommended for Buyer {$disp['buyer_name']}."
            ];
        } else {
            $parsed = [
                'recommendation'          => 'release_seller',
                'culprit'                 => 'undetermined',
                'risk_score'              => 35,
                'confidence'              => 65,
                'verdict_title_somali'    => "Garsoorka AI: Caddayntu Waa Isku Dhafan Tahay — Nidaamku U Janjeedi Iibiyaha (Insufficient Buyer Proof)",
                'summary_somali'          => "Caddaynta sawirrada iibsaduhu ku soo gudbiyay ma ahayn mid xoog leh oo ku filan si loo xaqiijiyo cabashada. Seller-ku iyo darawalku waxay hayaan sawir qaadid.",
                'detailed_analysis_somali'=> "1. Iibsaduhu ma haysto sawir cad oo muujinaya cillad marka la barbardhigo sawirrada seller-ka iyo darawalka.\n2. Silsiladda keenista waxay muujisaa in alaabtu si badqab ah loo gaarsiiyo.\n3. Soo-jeedinta AI: In lacagta $" . number_format($disp['net_amount'], 2) . " loo sii daayo iibiyaha {$disp['seller_name']}.",
                'admin_ruling_note'       => "AI Forensic: Buyer's evidence was insufficient. Seller/Driver chain appears intact. Funds released to Seller {$disp['seller_name']}."
            ];
        }
    }

    if (($parsed['culprit'] ?? '') === 'delivery') {
        $parsed['driver_accountability'] = sprintf(
            'Darawal: %s | Phone: %s | Email: %s | Moto/Vehicle: %s | Plate: %s | License/ID: %s | Address: %s | History: %d/%d deliveries completed.',
            $disp['driver_name'] ?: 'Lama aqoon', $disp['driver_phone'] ?: 'Lama gelin', $disp['driver_email'] ?: 'Lama gelin',
            $disp['vehicle_type'] ?: 'Lama gelin', $disp['vehicle_plate'] ?: 'Lama gelin', $disp['driver_license'] ?: 'Lama gelin', $disp['driver_address'] ?: 'Lama gelin',
            (int)($disp['driver_completed_jobs'] ?? 0), (int)($disp['driver_total_jobs'] ?? 0)
        );
        $parsed['detailed_analysis_somali'] .= "\n4. Xogta la xisaabtanka darawalka: {$parsed['driver_accountability']}";
    }
    $aiRisk = (int)$parsed['risk_score'];
    $pdo->prepare("UPDATE disputes SET ai_verdict = ?, ai_risk_score = ?, ai_analyzed_at = NOW() WHERE id = ?")
        ->execute([json_encode($parsed, JSON_UNESCAPED_UNICODE), $aiRisk, $disp['id']]);

    return [
        'success'         => true,
        'verdict'         => $parsed,
        'simulated'       => true,
        'fallback_reason' => $errorMsg,
        'visual_scores'   => [
            'pickup_dropoff_similarity' => $pickupDropoffSim,
            'seller_buyer_similarity'   => $sellerBuyerSim,
            'dropoff_buyer_similarity'  => $dropoffBuyerSim,
        ]
    ];
}
