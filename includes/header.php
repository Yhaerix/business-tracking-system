<?php
/**
 * Global Top Header & Layout Shell
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/rbac.php';

require_login();
$user = current_user();

// Fetch dynamic system alerts and notifications for the topbar bell
$allNotifications = [];
try {
    $db = get_db();

    // 1. Critical Out of Stock & Low Stock Alerts
    if (has_permission('inventory_view')) {
        $stmtLow = $db->query("
            SELECT id, name, sku, current_stock, low_stock_threshold 
            FROM products 
            WHERE current_stock <= low_stock_threshold 
              AND deleted_at IS NULL 
              AND status = 'active' 
            ORDER BY current_stock ASC, name ASC 
            LIMIT 6
        ");
        while ($pRow = $stmtLow->fetch(PDO::FETCH_ASSOC)) {
            $isZero = (int)$pRow['current_stock'] <= 0;
            $allNotifications[] = [
                'type'      => $isZero ? 'stock_out' : 'low_stock',
                'badge'     => $isZero ? 'Out of Stock' : 'Low Stock',
                'badge_cls' => $isZero ? 'badge-danger' : 'badge-warning',
                'title'     => ($isZero ? 'Out of Stock: ' : 'Low Stock: ') . $pRow['name'],
                'desc'      => $isZero 
                    ? '0 units available — immediate restock needed' 
                    : "{$pRow['current_stock']} left in stock (min threshold: {$pRow['low_stock_threshold']})",
                'url'       => BASE_URL . '/modules/inventory/',
                'time'      => 'Inventory',
                'dot_cls'   => $isZero ? 'notif-dot-danger' : 'notif-dot-warning',
                'icon'      => $isZero ? 'stock_out' : 'low_stock'
            ];
        }
    }

    // 2. Pending COD Cash Remittance (Courier delivered, cash awaiting drawer remittance)
    if (has_permission('orders_remit_cod')) {
        $stmtCod = $db->query("
            SELECT id, order_number, customer_name, total_amount, delivered_at 
            FROM orders 
            WHERE order_status = 'DELIVERED' 
              AND payment_status = 'COLLECTED' 
              AND deleted_at IS NULL 
            ORDER BY delivered_at DESC, id DESC 
            LIMIT 5
        ");
        while ($oRow = $stmtCod->fetch(PDO::FETCH_ASSOC)) {
            $formattedAmt = APP_CURRENCY . number_format((float)$oRow['total_amount'], 2);
            $timeStr = !empty($oRow['delivered_at']) ? date('M j, g:i A', strtotime($oRow['delivered_at'])) : 'Delivered';
            $allNotifications[] = [
                'type'      => 'cod_remit',
                'badge'     => 'COD Remit',
                'badge_cls' => 'badge-success',
                'title'     => "Remit Cash: #{$oRow['order_number']}",
                'desc'      => "{$formattedAmt} collected from {$oRow['customer_name']} awaiting drawer remittance",
                'url'       => BASE_URL . '/modules/orders/?status=DELIVERED',
                'time'      => $timeStr,
                'dot_cls'   => 'notif-dot-success',
                'icon'      => 'cash'
            ];
        }
    }

    // 3. Orders Confirmed & Awaiting Dispatch
    if (has_permission('orders_view')) {
        $stmtReady = $db->query("
            SELECT id, order_number, customer_name, total_amount, created_at 
            FROM orders 
            WHERE order_status = 'CONFIRMED' 
              AND deleted_at IS NULL 
            ORDER BY created_at ASC 
            LIMIT 5
        ");
        while ($oRow = $stmtReady->fetch(PDO::FETCH_ASSOC)) {
            $timeStr = !empty($oRow['created_at']) ? date('M j, g:i A', strtotime($oRow['created_at'])) : 'Recent';
            $allNotifications[] = [
                'type'      => 'dispatch_ready',
                'badge'     => 'Ready to Ship',
                'badge_cls' => 'badge-info',
                'title'     => "Ready to Ship: #{$oRow['order_number']}",
                'desc'      => "Customer: {$oRow['customer_name']} (" . APP_CURRENCY . number_format((float)$oRow['total_amount'], 2) . ") — assign courier",
                'url'       => BASE_URL . '/modules/orders/?status=CONFIRMED',
                'time'      => $timeStr,
                'dot_cls'   => 'notif-dot-info',
                'icon'      => 'truck'
            ];
        }
    }

    // 3b. Active Incoming Delivery & Scheduled Reminders
    if (has_permission('settings_view')) {
        $stmtRem = $db->query("
            SELECT id, title, type, target_date, notes
            FROM reminders
            WHERE is_active = 1
              AND is_completed = 0
              AND type IN ('INCOMING_DELIVERY', 'ORDER_DISPATCH')
            ORDER BY target_date ASC
            LIMIT 4
        ");
        while ($remRow = $stmtRem->fetch(PDO::FETCH_ASSOC)) {
            $remTimeStr = !empty($remRow['target_date']) ? date('M j, g:i A', strtotime($remRow['target_date'])) : 'Scheduled';
            $isIncoming = ($remRow['type'] === 'INCOMING_DELIVERY');
            $allNotifications[] = [
                'type'      => 'reminder_delivery',
                'badge'     => $isIncoming ? 'Incoming Delivery' : 'Order Delivery',
                'badge_cls' => $isIncoming ? 'badge-success' : 'badge-info',
                'title'     => $remRow['title'],
                'desc'      => !empty($remRow['notes']) ? $remRow['notes'] : "Scheduled for {$remTimeStr}",
                'url'       => BASE_URL . '/modules/settings/#reminders',
                'time'      => $remTimeStr,
                'dot_cls'   => $isIncoming ? 'notif-dot-success' : 'notif-dot-info',
                'icon'      => 'truck'
            ];
        }
    }

    // 4. Stored System Notifications
    $currentUid = (int)($user['id'] ?? 0);
    $stmtSys = $db->prepare("
        SELECT id, type, title, message, reference_type, reference_id, created_at 
        FROM notifications 
        WHERE is_read = 0 
          AND (user_id = :uid OR user_id IS NULL) 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmtSys->execute([':uid' => $currentUid]);
    while ($nRow = $stmtSys->fetch(PDO::FETCH_ASSOC)) {
        $timeStr = !empty($nRow['created_at']) ? date('M j, g:i A', strtotime($nRow['created_at'])) : 'Notice';
        $targetUrl = BASE_URL . '/modules/dashboard/';
        if ($nRow['reference_type'] === 'PRODUCT' || $nRow['type'] === 'LOW_STOCK') {
            $targetUrl = BASE_URL . '/modules/inventory/';
        } elseif ($nRow['reference_type'] === 'ORDER' || in_array($nRow['type'], ['COD_PENDING', 'ORDER_DISPATCHED'], true)) {
            $targetUrl = BASE_URL . '/modules/orders/';
        }
        $allNotifications[] = [
            'type'      => 'system',
            'badge'     => 'Notice',
            'badge_cls' => 'badge-secondary',
            'title'     => $nRow['title'],
            'desc'      => $nRow['message'],
            'url'       => $targetUrl,
            'time'      => $timeStr,
            'dot_cls'   => 'notif-dot-purple',
            'icon'      => 'bell'
        ];
    }
} catch (Throwable $e) {
    $allNotifications = [];
}
$notifCount = count($allNotifications);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' — ' : '' ?><?= APP_NAME ?></title>
    
    <!-- Google Fonts: Inter (400, 500, 600, 700, 800) with safe system fallback -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Design System Stylesheets -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Backdrop for Mobile Drawer -->
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <!-- Role-Aware Sidebar Navigation -->
        <?php include __DIR__ . '/sidebar.php'; ?>

        <!-- Main Content Area -->
        <div class="app-main">
            <!-- Top Navbar (Matching new-design.html) -->
            <header class="app-header">
                <div class="header-left">
                    <button type="button" class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle navigation">
                        ☰
                    </button>
                    <span class="page-breadcrumb" id="pageBreadcrumb">
                        <?= isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'Dashboard' ?>
                    </span>
                </div>

                <div class="header-right">
                    <!-- Notifications Dropdown -->
                    <div class="notif-wrap" id="notifWrap">
                        <button type="button" class="icon-btn" id="notifBtn" onclick="toggleNotifications(event)" aria-label="Notifications" title="System Alerts &amp; Notifications">
                            <svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0112 0c0 7 3 8 3 8H3s3-1 3-8M10 21a2 2 0 004 0"/></svg>
                            <?php if ($notifCount > 0): ?>
                                <span class="notif-count" id="notifCount"><?= $notifCount ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="notif-panel" id="notifPanel">
                            <div class="notif-head">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span>Alerts &amp; Notifications</span>
                                </div>
                                <?php if ($notifCount > 0): ?>
                                    <span class="badge badge-danger"><?= $notifCount ?> active</span>
                                <?php else: ?>
                                    <span class="badge badge-confirmed">All clear</span>
                                <?php endif; ?>
                            </div>
                            <div class="notif-body">
                                <?php if ($notifCount > 0): ?>
                                    <?php foreach ($allNotifications as $item): ?>
                                        <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>" class="notif-item">
                                            <div class="notif-dot <?= htmlspecialchars($item['dot_cls'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?php if ($item['icon'] === 'stock_out'): ?>
                                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                                <?php elseif ($item['icon'] === 'cash'): ?>
                                                    <svg viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>
                                                <?php elseif ($item['icon'] === 'truck'): ?>
                                                    <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                                <?php elseif ($item['icon'] === 'bell'): ?>
                                                    <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
                                                <?php else: /* low_stock */ ?>
                                                    <svg viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notif-content">
                                                <div class="notif-meta-row">
                                                    <span class="notif-badge-tag <?= htmlspecialchars($item['badge_cls'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($item['badge'], ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                    <span class="notif-time"><?= htmlspecialchars($item['time'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>
                                                <div class="notif-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="notif-sub"><?= htmlspecialchars($item['desc'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notif-empty">
                                        <div class="notif-empty-icon">✓</div>
                                        <div style="font-weight: 700; font-size: 13.5px; color: var(--text-main); margin-bottom: 2px;">All Caught Up!</div>
                                        <div style="font-size: 12px; color: var(--text-muted); line-height: 1.4;">No urgent alerts, stock warnings, or pending tasks right now.</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- User Profile Chip & Menu -->
                    <div class="profile-wrap" id="profileWrap">
                        <div class="profile-chip" onclick="toggleProfileMenu(event)" role="button" tabindex="0" title="Account settings">
                            <span class="avatar">
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg>
                            </span>
                            <div class="user-badge">
                                <span class="user-name"><?= htmlspecialchars($user['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="user-role"><?= htmlspecialchars($user['role_label'] ?? 'Staff', ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <svg class="chev" viewBox="0 0 24 24" style="stroke:currentColor;fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round"><path d="M6 9l6 6 6-6"/></svg>
                        </div>
                        <div class="profile-menu" id="profileMenu">
                            <div class="menu-label">Signed in as <strong>@<?= htmlspecialchars($user['username'] ?? 'user', ENT_QUOTES, 'UTF-8') ?></strong></div>
                            <div class="menu-label" style="font-size:11px;color:var(--color-text-muted);margin-top:2px;">Role: <?= htmlspecialchars($user['role_label'] ?? 'Staff', ENT_QUOTES, 'UTF-8') ?></div>
                            <hr>
                            <button type="button" class="btn btn-outline btn-sm menu-msg" onclick="openChat()" style="width:100%;display:flex;align-items:center;justify-content:flex-start;gap:8px;padding:8px 12px;margin-bottom:6px;">
                                <svg viewBox="0 0 24 24" width="15" height="15" style="stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><path d="M21 12a8 8 0 01-11.6 7.1L4 20l1-4.6A8 8 0 1121 12z"/></svg>
                                <span>Messages</span>
                                <span class="chat-badge" id="chatBadge">0</span>
                            </button>
                            <a href="<?= BASE_URL ?>/modules/auth/logout.php" class="btn btn-outline btn-sm" style="width:100%;display:flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;">
                                <svg viewBox="0 0 24 24" width="14" height="14" style="stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><path d="M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H9"/></svg>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Body Container -->
            <main class="page-content">
