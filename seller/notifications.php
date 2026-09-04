<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['seller']);
$page_title  = 'Notifications';
$active_page = 'notifications.php';
require __DIR__ . '/../includes/notifications_page.php';
