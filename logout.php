<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    logAudit('LOGOUT', 'User logged out');
}
session_destroy();
header('Location: ' . APP_URL . '/index.php');
exit;
