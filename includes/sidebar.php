<?php
// sidebar.php — Role-based navigation sidebar
// Requires: $user (array), $active_page (string)

$role        = $user['role'];
$active_page = $active_page ?? '';
$base        = APP_URL . '/' . $role;

// Define nav items per role
$nav_items = [];

if ($role === 'superadmin') {
    $nav_items = [
        ['icon' => 'ri-dashboard-line',       'label' => 'Dashboard',         'href' => $base . '/dashboard.php'],
        ['icon' => 'ri-shopping-bag-3-line',  'label' => 'Products',          'href' => $base . '/products.php'],
        ['icon' => 'ri-secure-payment-line',  'label' => 'Escrow & Orders',   'href' => $base . '/transactions.php'],
        ['icon' => 'ri-truck-line',           'label' => 'Deliveries Hub',    'href' => $base . '/deliveries.php'],
        ['icon' => 'ri-scales-3-line',        'label' => 'Disputes',          'href' => $base . '/disputes.php'],
        ['icon' => 'ri-wallet-3-line',        'label' => 'Wallets',           'href' => $base . '/wallets.php'],
        ['icon' => 'ri-bank-line',            'label' => 'Withdrawals',       'href' => $base . '/withdrawals.php'],
        ['icon' => 'ri-group-line',           'label' => 'Users',             'href' => $base . '/users.php'],
        ['icon' => 'ri-bar-chart-2-line',     'label' => 'Analytics',         'href' => $base . '/analytics.php'],
        ['icon' => 'ri-file-chart-line',      'label' => 'Reports',           'href' => $base . '/reports.php'],
        ['icon' => 'ri-message-3-line',       'label' => 'Messaging',         'href' => $base . '/messaging.php'],
        ['icon' => 'ri-shield-keyhole-line',  'label' => 'Scam Shield',       'href' => $base . '/scam_shield.php'],
        ['icon' => 'ri-notification-3-line',  'label' => 'Notifications',     'href' => $base . '/notifications.php'],
        ['icon' => 'ri-history-line',         'label' => 'Audit Logs',        'href' => $base . '/logs.php'],
        ['icon' => 'ri-settings-3-line',      'label' => 'Settings',          'href' => $base . '/settings.php'],
        ['icon' => 'ri-user-settings-line',   'label' => 'Profile',           'href' => $base . '/profile.php'],
    ];
} elseif ($role === 'buyer') {
    $nav_items = [
        ['icon' => 'ri-dashboard-line',       'label' => 'Dashboard',         'href' => $base . '/dashboard.php'],
        ['icon' => 'ri-shopping-cart-2-line', 'label' => 'Marketplace',       'href' => $base . '/marketplace.php'],
        ['icon' => 'ri-file-list-3-line',     'label' => 'My Orders',         'href' => $base . '/my_orders.php'],
        ['icon' => 'ri-add-circle-line',      'label' => 'Custom Escrow',     'href' => $base . '/new_order.php'],
        ['icon' => 'ri-wallet-3-line',        'label' => 'My Wallet',         'href' => $base . '/wallet.php'],
        ['icon' => 'ri-message-3-line',       'label' => 'Messaging',         'href' => $base . '/messaging.php'],
        ['icon' => 'ri-notification-3-line',  'label' => 'Notifications',     'href' => $base . '/notifications.php'],
        ['icon' => 'ri-user-settings-line',   'label' => 'Profile',           'href' => $base . '/profile.php'],
    ];
} elseif ($role === 'seller') {
    $nav_items = [
        ['icon' => 'ri-dashboard-line',       'label' => 'Dashboard',         'href' => $base . '/dashboard.php'],
        ['icon' => 'ri-store-2-line',         'label' => 'My Products',       'href' => $base . '/products.php'],
        ['icon' => 'ri-shopping-bag-line',    'label' => 'Orders',            'href' => $base . '/orders.php'],
        ['icon' => 'ri-wallet-3-line',        'label' => 'My Wallet',         'href' => $base . '/wallet.php'],
        ['icon' => 'ri-message-3-line',       'label' => 'Messaging',         'href' => $base . '/messaging.php'],
        ['icon' => 'ri-notification-3-line',  'label' => 'Notifications',     'href' => $base . '/notifications.php'],
        ['icon' => 'ri-user-settings-line',   'label' => 'Profile',           'href' => $base . '/profile.php'],
    ];
} elseif ($role === 'delivery') {
    $nav_items = [
        ['icon' => 'ri-dashboard-line',       'label' => 'Dashboard',         'href' => $base . '/dashboard.php'],
        ['icon' => 'ri-truck-line',           'label' => 'Deliveries',        'href' => $base . '/deliveries.php'],
        ['icon' => 'ri-wallet-3-line',        'label' => 'My Wallet',         'href' => $base . '/wallet.php'],
        ['icon' => 'ri-time-line',            'label' => 'History',           'href' => $base . '/history.php'],
        ['icon' => 'ri-message-3-line',       'label' => 'Messaging',         'href' => $base . '/messaging.php'],
        ['icon' => 'ri-notification-3-line',  'label' => 'Notifications',     'href' => $base . '/notifications.php'],
        ['icon' => 'ri-user-settings-line',   'label' => 'Profile',           'href' => $base . '/profile.php'],
    ];
}
?>

<!-- ==================== SIDEBAR ==================== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
        <div class="sidebar-brand" style="display:flex;align-items:center;gap:12px;"><div class="sidebar-brand-icon" style="background:#fff;padding:3px;width:38px;height:38px;border-radius:10px;overflow:hidden;flex-shrink:0;"><img src="<?= APP_URL ?>/assets/logo/image.png" alt="Logo" style="width:100%;height:100%;object-fit:contain;"></div><div class="sidebar-brand-text"><span class="brand-title"><?= sanitize(getSetting('site_name', APP_NAME)) ?></span><span class="brand-sub">Secure Escrow</span></div></div>
        <!-- Navigation -->
        <nav class="sidebar-nav">
            <ul class="nav-list">
                <?php foreach ($nav_items as $item):
                    $is_active = (basename($item['href']) === $active_page);
                ?>
                <li class="nav-item <?= $is_active ? 'active' : '' ?>">
                    <a href="<?= $item['href'] ?>" class="nav-link">
                        <span class="nav-icon"><i class="<?= $item['icon'] ?>"></i></span>
                        <span class="nav-label"><?= $item['label'] ?></span>
                        <?php if ($is_active): ?><span class="nav-active-bar"></span><?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <div class="sidebar-balance">
                <span class="balance-label"><i class="ri-wallet-3-line"></i> Balance</span>
                <span class="balance-amount"><?= formatCurrency((float)$user['balance']) ?></span>
            </div>
            <a href="<?= APP_URL ?>/logout.php" class="sidebar-logout">
                <i class="ri-logout-box-r-line"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</aside>

<!-- App Wrapper (contains main content) -->
<div class="app-wrapper" id="appWrapper">
<main class="main-content" id="mainContent">
