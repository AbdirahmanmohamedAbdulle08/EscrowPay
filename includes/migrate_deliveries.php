<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();

try {
    $pdo->exec("ALTER TABLE deliveries MODIFY COLUMN delivery_id INT NULL");
    $pdo->exec("ALTER TABLE deliveries MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending_admin'");
} catch (Exception $e) {
    echo "Status/delivery_id note: " . $e->getMessage() . "\n";
}

$cols = $pdo->query("SHOW COLUMNS FROM deliveries")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('requested_by', $cols)) {
    $pdo->exec("ALTER TABLE deliveries ADD COLUMN requested_by INT NULL AFTER delivery_id");
}
if (!in_array('delivery_accepted', $cols)) {
    $pdo->exec("ALTER TABLE deliveries ADD COLUMN delivery_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
}
if (!in_array('admin_approved', $cols)) {
    $pdo->exec("ALTER TABLE deliveries ADD COLUMN admin_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_accepted");
}
if (!in_array('reject_reason', $cols)) {
    $pdo->exec("ALTER TABLE deliveries ADD COLUMN reject_reason TEXT NULL AFTER notes");
}

echo "MIGRATION_COMPLETE\n";
