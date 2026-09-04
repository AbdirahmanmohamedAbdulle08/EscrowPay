<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();

$chk = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key`='delivery_fee'");
$chk->execute();
if ($chk->fetchColumn() > 0) {
    $pdo->prepare("UPDATE settings SET `value`='1.50' WHERE `key`='delivery_fee'")->execute();
} else {
    $pdo->prepare("INSERT INTO settings (`key`, `value`, description) VALUES ('delivery_fee', '1.50', 'Standard Delivery Fee')")->execute();
}

echo "DB_DELIVERY_FEE_UPDATED_TO_1.50\n";
