<?php
// header.php — HTML <head> + topbar
// Variables expected: $page_title (string), $user (array)
$unread_notifs   = countUnread();
$unread_messages = countUnreadMessages();
$site_name       = getSetting('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="en" data-app-url="<?= APP_URL ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= $site_name ?> — Secure Escrow Payment Platform">
    <title><?= sanitize($page_title ?? 'Dashboard') ?> — <?= sanitize($site_name) ?></title>

    <!-- Favicon & App Icons -->
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/logo/image.png">
    <link rel="shortcut icon" type="image/png" href="<?= APP_URL ?>/assets/logo/image.png">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/logo/image.png">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Remix Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css" rel="stylesheet">

    <!-- Chart.js (loaded globally, used on analytics/reports) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

    <!-- App CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <?php if (($user['role'] ?? '') === 'superadmin'): ?>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/superadmin.css">
    <?php endif; ?>
</head>
<body class="role-<?= sanitize($user['role'] ?? 'guest') ?>">

<!-- ==================== TOPBAR ==================== -->
<header class="topbar" id="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="ri-menu-line"></i>
        </button>
    </div>

    <div class="topbar-right">
        <!-- Search -->
        <div class="topbar-search">
            <i class="ri-search-line"></i>
            <input type="text" placeholder="Search..." id="globalSearch" autocomplete="off">
        </div>

        <!-- Messages -->
        <a href="<?= APP_URL ?>/<?= $user['role'] ?>/messaging.php" class="topbar-icon-btn" title="Messages">
            <i class="ri-message-3-line"></i>
            <?php if ($unread_messages > 0): ?>
                <span class="badge-dot badge-primary"><?= $unread_messages ?></span>
            <?php endif; ?>
        </a>

        <!-- Notifications -->
        <button class="topbar-icon-btn" id="notifToggle" title="Notifications">
            <i class="ri-notification-3-line"></i>
            <?php if ($unread_notifs > 0): ?>
                <span class="badge-dot badge-danger"><?= $unread_notifs ?></span>
            <?php endif; ?>
        </button>

        <!-- Notification Dropdown -->
        <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-header">
                <span>Notifications</span>
                <a href="<?= APP_URL ?>/<?= $user['role'] ?>/notifications.php">View all</a>
            </div>
            <div class="notif-list" id="notifList">
                <div class="notif-loading"><i class="ri-loader-4-line spin"></i> Loading…</div>
            </div>
        </div>

        <!-- User menu -->
        <div class="user-menu" id="userMenuWrapper">
            <button class="user-avatar-btn" id="userMenuToggle">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= APP_URL ?>/assets/img/avatars/<?= sanitize($user['avatar']) ?>" alt="<?= sanitize($user['name']) ?>" class="avatar-img">
                <?php else: ?>
                    <div class="avatar-placeholder"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
                <?php endif; ?>
                <span class="user-name-text"><?= sanitize(explode(' ', $user['name'])[0]) ?></span>
                <i class="ri-arrow-down-s-line"></i>
            </button>
            <div class="user-dropdown" id="userDropdown">
                <div class="user-dropdown-header">
                    <strong><?= sanitize($user['name']) ?></strong>
                    <small><?= sanitize($user['email']) ?></small>
                    <span class="role-tag role-<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span>
                </div>
                <a href="<?= APP_URL ?>/<?= $user['role'] ?>/profile.php"><i class="ri-user-line"></i> My Profile</a>
                <a href="<?= APP_URL ?>/<?= $user['role'] ?>/notifications.php"><i class="ri-notification-line"></i> Notifications</a>
                <div class="dropdown-divider"></div>
                <a href="<?= APP_URL ?>/logout.php" class="logout-link"><i class="ri-logout-box-r-line"></i> Logout</a>
            </div>
        </div>
    </div>
</header>
<!-- Notification overlay -->
<div class="overlay" id="overlay"></div>
