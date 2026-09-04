<?php
// ============================================================
// AUTH GUARD — Session management & role-based access
// ============================================================
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ============================================================
// requireLogin($allowed_roles)
// Redirect to login if not authenticated or wrong role.
// ============================================================
function requireLogin(array $allowed_roles = []): array {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php?error=session_expired');
        exit;
    }

    $user = getCurrentUser();
    if (!$user) {
        session_destroy();
        header('Location: ' . APP_URL . '/login.php?error=invalid_user');
        exit;
    }

    if ($user['status'] === 'suspended') {
        session_destroy();
        header('Location: ' . APP_URL . '/login.php?error=suspended');
        exit;
    }

    if (!empty($allowed_roles) && !in_array($user['role'], $allowed_roles, true)) {
        header('Location: ' . APP_URL . '/login.php?error=unauthorized');
        exit;
    }

    return $user;
}

// ============================================================
// getCurrentUser() — fetch from DB by session id
// ============================================================
function getCurrentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;

    static $cache = null;
    if ($cache !== null) return $cache;

    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT id, name, email, role, avatar, phone, address, balance, status, last_login, created_at FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $cache = $stmt->fetch() ?: null;
    return $cache;
}

// ============================================================
// logAudit() — write to audit_logs
// ============================================================
function logAudit(string $action, string $details = '', ?int $user_id = null): void {
    try {
        $pdo  = getDB();
        $uid  = $user_id ?? ($_SESSION['user_id'] ?? null);
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$uid, $action, $details, $ip, $ua]);
    } catch (Exception $e) {
        // Silent fail for logging
    }
}

// ============================================================
// addNotification() — insert a notification for a user
// ============================================================
function addNotification(int $user_id, string $title, string $message, string $type = 'info', string $link = ''): void {
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $title, $message, $type, $link]);
    } catch (Exception $e) {
        // Silent fail
    }
}

// ============================================================
// countUnread() — unread notifications for current user
// ============================================================
function countUnread(): int {
    if (empty($_SESSION['user_id'])) return 0;
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// ============================================================
// countUnreadMessages() — unread messages for current user
// ============================================================
function countUnreadMessages(): int {
    if (empty($_SESSION['user_id'])) return 0;
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// ============================================================
// Redirect helpers
// ============================================================
function redirectByRole(string $role): void {
    $map = [
        'superadmin' => APP_URL . '/superadmin/dashboard.php',
        'buyer'      => APP_URL . '/buyer/dashboard.php',
        'seller'     => APP_URL . '/seller/dashboard.php',
        'delivery'   => APP_URL . '/delivery/dashboard.php',
    ];
    header('Location: ' . ($map[$role] ?? APP_URL . '/index.php'));
    exit;
}

// ============================================================
// formatCurrency()
// ============================================================
function formatCurrency(float $amount): string {
    $symbol = getSetting('currency_symbol', '$');
    return $symbol . number_format($amount, 2);
}

// ============================================================
// timeAgo()
// ============================================================
function timeAgo(string $datetime): string {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60)      return $diff . 's ago';
    if ($diff < 3600)    return floor($diff / 60) . 'm ago';
    if ($diff < 86400)   return floor($diff / 3600) . 'h ago';
    if ($diff < 604800)  return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}

// ============================================================
// statusBadge()
// ============================================================
function statusBadge(string $status): string {
    $map = [
        'pending'   => ['badge-warning',  'Pending'],
        'funded'    => ['badge-info',     'Funded'],
        'accepted'  => ['badge-primary',  'Accepted'],
        'shipped'   => ['badge-primary',  'Shipped'],
        'delivered' => ['badge-success',  'Delivered'],
        'released'  => ['badge-success',  'Released'],
        'disputed'  => ['badge-danger',   'Disputed'],
        'cancelled' => ['badge-neutral',  'Cancelled'],
        'active'    => ['badge-success',  'Active'],
        'suspended' => ['badge-danger',   'Suspended'],
        'assigned'  => ['badge-info',     'Assigned'],
        'picked_up' => ['badge-primary',  'Picked Up'],
        'in_transit'=> ['badge-primary',  'In Transit'],
    ];
    $data = $map[$status] ?? ['badge-neutral', ucfirst($status)];
    return '<span class="badge ' . $data[0] . '">' . $data[1] . '</span>';
}

// ============================================================
// generateRefCode()
// ============================================================
function generateRefCode(): string {
    $pdo = getDB();
    do {
        $code = 'ESC-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT id FROM transactions WHERE ref_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

// ============================================================
// sanitize()
// ============================================================
function sanitize(string $str): string {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}
