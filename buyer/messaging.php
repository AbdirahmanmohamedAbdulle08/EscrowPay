<?php
// Generic messaging page — works for buyer, seller, delivery
// Set $allowed_role before including, or use requireLogin with role array
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['buyer']);
$page_title  = 'Messages';
$active_page = 'messaging.php';

// Include the shared messaging logic
$role_folder = 'buyer';
require __DIR__ . '/../includes/messaging_page.php';
