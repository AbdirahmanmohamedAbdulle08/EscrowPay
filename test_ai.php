<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/gemini_helper.php';

$base = __DIR__ . '/';
$imgs = [
    'Seller'  => 'uploads/proofs/proof_seller_7_1788119819.jpg',
    'Pickup'  => 'uploads/proofs/proof_pickup_7_1788119964.jpg',
    'Dropoff' => 'uploads/proofs/proof_dropoff_7_1788119977.jpg',
    'Buyer'   => 'uploads/proofs/proof_dispute_7_1788120027.jpg',
];

echo "--- Image Color Profiles ---\n";
$avgColors = [];
foreach ($imgs as $name => $path) {
    if (file_exists($base . $path)) {
        $img = imagecreatefromjpeg($base . $path);
        $w = imagesx($img); $h = imagesy($img);
        $r = 0; $g = 0; $b = 0; $n = 0;
        $stepX = max(1, (int)($w / 20));
        $stepY = max(1, (int)($h / 20));
        for ($x = 0; $x < $w; $x += $stepX) {
            for ($y = 0; $y < $h; $y += $stepY) {
                $c = imagecolorat($img, $x, $y);
                $r += ($c >> 16 & 0xFF);
                $g += ($c >> 8 & 0xFF);
                $b += ($c & 0xFF);
                $n++;
            }
        }
        imagedestroy($img);
        $ar = (int)($r / $n); $ag = (int)($g / $n); $ab = (int)($b / $n);
        $avgColors[$name] = [$ar, $ag, $ab];
        echo "$name ($w x $h): avg RGB($ar, $ag, $ab)\n";
    } else {
        echo "$name: FILE NOT FOUND - $path\n";
    }
}

// Cross-compare using our function
echo "\n--- Direct Similarity Matrix ---\n";
$paths = array_combine(array_keys($imgs), array_map(fn($p) => $base . $p, $imgs));
foreach ($paths as $n1 => $p1) {
    foreach ($paths as $n2 => $p2) {
        if ($n1 >= $n2) continue;
        $sim = compareImageSimilarity($p1, $p2);
        $flag = $sim < 40 ? "<<< TAMPER ALERT" : ($sim >= 70 ? "(very similar)" : ($sim >= 55 ? "(moderate)" : "(different)"));
        echo "$n1 vs $n2: {$sim}% $flag\n";
    }
}

// Check what verdict the fallback gives now 
echo "\n--- Fallback Verdict For Dispute ID 2 ---\n";
$res = analyzeDisputeWithGemini(2);
echo "Verdict: " . strtoupper($res['verdict']['recommendation'] ?? 'N/A') . "\n";
echo "Culprit: " . ($res['verdict']['culprit'] ?? 'N/A') . "\n";
echo "Score:   " . ($res['verdict']['risk_score'] ?? 'N/A') . "%\n";
echo "Simulated: " . (!empty($res['simulated']) ? 'YES - ' . $res['fallback_reason'] : 'NO - Live Gemini') . "\n";
if (!empty($res['visual_scores'])) {
    foreach ($res['visual_scores'] as $k => $v) echo "  $k: $v%\n";
}
echo "\n" . ($res['verdict']['verdict_title_somali'] ?? '') . "\n";
