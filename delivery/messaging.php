<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['delivery']);
$page_title  = 'Messages';
$active_page = 'messaging.php';
$role_folder = 'delivery';
require __DIR__ . '/../includes/messaging_page.php';
