<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['seller']);
$page_title  = 'Messages';
$active_page = 'messaging.php';
$role_folder = 'seller';
require __DIR__ . '/../includes/messaging_page.php';
