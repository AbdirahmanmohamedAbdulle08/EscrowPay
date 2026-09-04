<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['buyer']);
$page_title  = 'My Profile';
$active_page = 'profile.php';

require __DIR__ . '/../includes/profile_page.php';
