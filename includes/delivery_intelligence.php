<?php
/** Smart delivery matching and transparent driver trust scoring. */
function ensureDeliveryIntelligence(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_presence (delivery_id INT PRIMARY KEY, latitude DECIMAL(10,7) NULL, longitude DECIMAL(10,7) NULL, is_available TINYINT(1) NOT NULL DEFAULT 1, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (delivery_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
    $columns = $pdo->query('SHOW COLUMNS FROM deliveries')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('dropoff_latitude', $columns, true)) $pdo->exec('ALTER TABLE deliveries ADD COLUMN dropoff_latitude DECIMAL(10,7) NULL');
    if (!in_array('dropoff_longitude', $columns, true)) $pdo->exec('ALTER TABLE deliveries ADD COLUMN dropoff_longitude DECIMAL(10,7) NULL');
    if (!in_array('match_score', $columns, true)) $pdo->exec('ALTER TABLE deliveries ADD COLUMN match_score TINYINT UNSIGNED NULL');
}

function deliveryTrustScore(PDO $pdo, int $driverId): array {
    $st = $pdo->prepare("SELECT COUNT(*) total, SUM(status='delivered') completed, SUM(status='open_pool') declined FROM deliveries WHERE delivery_id=?");
    $st->execute([$driverId]); $d = $st->fetch() ?: [];
    $total = (int)($d['total'] ?? 0); $completed = (int)($d['completed'] ?? 0);
    $rate = $total ? $completed / $total : 0.70;
    $risk = $pdo->prepare("SELECT COUNT(*) FROM chat_moderation_events WHERE sender_id=? AND action_taken IN ('block','review')");
    try { $risk->execute([$driverId]); $flags = (int)$risk->fetchColumn(); } catch (Throwable $e) { $flags = 0; }
    $score = (int)round(min(100, max(0, 35 + ($rate * 45) + min(20, $completed * 2) - min(30, $flags * 10))));
    $badge = $score >= 90 ? 'Trusted Pro' : ($score >= 75 ? 'Reliable' : ($score >= 55 ? 'Building Trust' : 'Needs Review'));
    return ['score' => $score, 'badge' => $badge, 'completed' => $completed, 'total' => $total, 'flags' => $flags];
}

function smartMatchDelivery(PDO $pdo, ?float $lat, ?float $lng, string $address): ?array {
    ensureDeliveryIntelligence($pdo);
    $drivers = $pdo->query("SELECT u.id, u.address, COALESCE(dp.latitude,NULL) latitude, COALESCE(dp.longitude,NULL) longitude, COALESCE(dp.is_available,1) available, (SELECT COUNT(*) FROM deliveries a WHERE a.delivery_id=u.id AND a.status IN ('assigned','picked_up','in_transit')) active_jobs FROM users u LEFT JOIN delivery_presence dp ON dp.delivery_id=u.id WHERE u.role='delivery' AND u.status='active' AND COALESCE(dp.is_available,1)=1")->fetchAll();
    $best = null;
    foreach ($drivers as $driver) {
        $trust = deliveryTrustScore($pdo, (int)$driver['id']);
        $distance = null;
        if ($lat !== null && $lng !== null && $driver['latitude'] !== null && $driver['longitude'] !== null) {
            $theta = deg2rad($lng - (float)$driver['longitude']);
            $distance = 6371 * acos(min(1, max(-1, sin(deg2rad($lat))*sin(deg2rad((float)$driver['latitude'])) + cos(deg2rad($lat))*cos(deg2rad((float)$driver['latitude']))*cos($theta))));
        }
        $addressBonus = $address !== '' && !empty($driver['address']) && str_contains(mb_strtolower($driver['address']), mb_strtolower(explode(' ', $address)[0])) ? 10 : 0;
        $proximity = $distance === null ? $addressBonus : max(0, 30 - min(30, $distance));
        $score = (int)round(($trust['score'] * .7) + $proximity - ((int)$driver['active_jobs'] * 7));
        if ($best === null || $score > $best['match_score']) $best = $driver + ['match_score' => max(0, min(100, $score)), 'distance_km' => $distance, 'trust' => $trust];
    }
    return $best;
}
