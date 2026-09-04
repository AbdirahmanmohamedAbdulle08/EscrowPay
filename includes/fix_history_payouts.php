<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();

// 1. Update transactions table
$pdo->exec("UPDATE transactions SET delivery_fee = 1.50 WHERE delivery_fee = 15.00 OR delivery_fee IS NULL");
$pdo->exec("UPDATE transactions SET delivery_commission = 0.00 WHERE delivery_commission = 1.50");

// 2. Update wallet_transactions table for delivery payouts
$pdo->exec("UPDATE wallet_transactions SET amount = 1.50 WHERE payment_method = 'delivery_payout' OR description LIKE 'Delivery Payout%'");

// 3. Clean up any erratic negative numbers in wallet transactions notes
$pdo->exec("UPDATE withdrawals SET fee = 0.01 WHERE fee = 2.50 AND amount = 1.00");

echo "HISTORICAL_RECORDS_UPDATED_TO_1.50\n";
