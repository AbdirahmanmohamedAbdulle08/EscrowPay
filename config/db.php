<?php
// ============================================================
// DATABASE CONFIGURATION
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'escrow_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// App Settings
define('APP_NAME', 'EscrowPay');
define('APP_URL',  'http://localhost/es');
define('APP_VERSION', '1.0.0');

// Gemini AI: server-side key used by the AI product, dispute, and shopping tools.
// Keep this file outside public version control when deploying to production.
define('GEMINI_API_KEY', 'HALKAN_GALI_KEY_GAAGA_API_KEY');

// Session lifetime (seconds)
define('SESSION_LIFETIME', 3600);

// ============================================================
// PDO Connection
// ============================================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode([
                'error' => true,
                'message' => 'Database connection failed: ' . $e->getMessage()
            ]));
        }
    }
    return $pdo;
}

// ============================================================
// Helper: Get setting value from DB
// ============================================================
function getSetting(string $key, string $default = ''): string {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}
