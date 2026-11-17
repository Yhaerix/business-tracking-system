<?php
/**
 * Public Customer Storefront Top Navigation & Header
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 * Contains the Global Slide-Out #cartDrawer accessible across all storefront views
 */

declare(strict_types=1);

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';
$coinRate = 1.00;

try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/auth.php';
    $dbHeader = Database::getConnection();
    sync_admin_storefront_session($dbHeader);

    $stmtRate = $dbHeader->query("SELECT setting_value FROM store_settings WHERE setting_key = 'coins_conversion_rate' LIMIT 1");
    $rateVal = $stmtRate ? $stmtRate->fetchColumn() : false;
    if ($rateVal !== false && is_numeric($rateVal) && (float) $rateVal > 0) {
        $coinRate = (float) $rateVal;
    }
    if (!empty($_SESSION['customer_id'])) {
        $stmtW = $dbHeader->prepare("SELECT coin_balance FROM customer_wallets WHERE customer_id = :cid LIMIT 1");
        $stmtW->execute([':cid' => (int) $_SESSION['customer_id']]);
        $wBal = $stmtW->fetchColumn();
        if ($wBal !== false) {
            $_SESSION['customer_coins'] = (float) $wBal;
        }
    }
} catch (\Throwable $e) {
}

$isCustomerLoggedIn = !empty($_SESSION['customer_id']);
$customerName = $_SESSION['customer_name'] ?? 'Prestige Customer';
$customerEmail = $_SESSION['customer_email'] ?? '';
$customerCoins = (float) ($_SESSION['customer_coins'] ?? 0.00);
$formattedCoins = number_format($customerCoins, 2) . ' Coins';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' — ' : '' ?>Prestige Apple Store
        Philippines</title>

    <!-- SF Pro System Fonts with Apple Fallbacks -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Storefront Aesthetic Stylesheet -->
    <link rel="stylesheet" href="../assets/css/store.css?v=<?= time() ?>">
</head>

<body class="store-body">

    <!-- 1. Top Announcement Ribbon -->
    <div class="store-topbar">
        <div class="store-container store-topbar-inner">
            <div class="topbar-announcement">
                <span class="topbar-badge">Official Store</span>
                <?php if ($isCustomerLoggedIn): ?>
                    <span>Welcome back, <strong><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></strong>!
                        Insured Nationwide Shipping on Orders Over ₱5,000</span>
                <?php else: ?>
                    <span>Free Insured Nationwide Shipping on Orders Over ₱5,000 | 100% Authentic Apple Devices</span>
                <?php endif; ?>
            </div>
            <div class="topbar-links">
                <a href="#trustSection">Why Buy From Us</a>
                <span>|</span>
                <a href="javascript:void(0)" onclick="openTopUpModal()">Prestige Coins</a>
                <span>|</span>
                <a href="track-order.php">Track Order</a>
                <span>|</span>
                <?php if ($isCustomerLoggedIn): ?>
                    <a href="javascript:void(0)" onclick="handleCustomerSignOut()">Sign Out</a>
                <?php else: ?>
                    <a href="login.php">Sign In</a>
                <?php endif; ?>
                <span>|</span>
                <a href="../modules/dashboard/" target="_blank">Staff Portal</a>
            </div>
        </div>
    </div>

    <!-- 2. Sticky Glassmorphism Header -->
    <header class="store-header" id="storeHeader">
        <div class="store-container store-header-inner">
            <!-- Brand Logo -->
            <a href="index.php" class="store-brand" title="Prestige Apple Store Home">
                <svg viewBox="0 0 170 170">
                    <path
                        d="M150.37 130.25c-2.45 5.66-5.35 10.87-8.71 15.66-4.58 6.53-8.33 11.05-11.22 13.56-4.48 4.12-9.28 6.23-14.42 6.35-3.69 0-8.14-1.05-13.32-3.18-5.19-2.12-9.97-3.17-14.34-3.17-4.58 0-9.49 1.05-14.75 3.17-5.26 2.13-9.5 3.24-12.74 3.35-4.35.13-9.16-1.9-14.42-6.08-3.69-3.04-7.56-7.71-11.61-14.01-6.19-9.56-11.07-20.59-14.65-33.09-3.58-12.5-5.37-24.16-5.37-34.98 0-14.28 3.52-26.07 10.57-35.37 7.05-9.3 15.82-14.07 26.31-14.32 5.08 0 10.42 1.25 16.03 3.75 5.6 2.5 9.42 3.81 11.45 3.93 1.8.13 5.86-1.25 12.18-4.14 6.32-2.89 11.75-4.19 16.29-3.9 11.96.98 21.6 5.37 28.91 13.18-10.47 6.38-15.6 15.22-15.39 26.52.22 8.94 3.75 16.51 10.59 22.71 6.84 6.2 14.85 9.77 24.03 10.71-2.22 6.5-4.88 13.06-7.98 19.68zM119.22 31.84c0-7.2 2.66-13.88 7.98-20.04 5.32-6.16 11.77-9.93 19.35-11.31.25 1.15.38 2.21.38 3.19 0 7.37-2.82 14.26-8.46 20.67-5.64 6.41-12.35 10.23-20.13 11.46-.38-1.31-.57-2.64-.57-3.97z" />
                </svg>
                <span>Prestige <span class="accent">Apple</span></span>
            </a>

            <!-- Search Bar with Live Hot Chips -->
            <div class="search-wrapper">
                <div class="search-bar">
                    <svg class="search-icon" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                    <input type="text" id="storeSearchInput" class="search-input"
                        placeholder="Search iPhone, MacBook, AirPods, iPad, or specs..." autocomplete="off">
                    <button type="button" class="search-clear-btn" id="searchClearBtn" onclick="clearStoreSearch()"
                        aria-label="Clear Search">&times;</button>
                </div>
                <div class="search-chips">
                    <span class="search-chip" onclick="applySearchChip('iPhone 16 Pro')">iPhone 16 Pro</span>
                    <span class="search-chip" onclick="applySearchChip('MacBook Air M3')">MacBook Air M3</span>
                    <span class="search-chip" onclick="applySearchChip('AirPods Pro')">AirPods Pro</span>
                    <span class="search-chip" onclick="applySearchChip('Apple Watch')">Apple Watch</span>
                    <span class="search-chip" onclick="applySearchChip('iPad Pro M4')">iPad Pro M4</span>
                </div>
            </div>

            <!-- Header Action Widgets -->
            <div class="header-actions">
                <!-- Prestige Coins Widget Pill -->
                <div class="coins-widget-pill" onclick="openTopUpModal()" title="View & Top Up Prestige Apple Coins">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M12 6v12M15 9.5a3.5 3.5 0 0 0-5 0c0 2 5 2 5 5a3.5 3.5 0 0 1-5 0"></path>
                    </svg>
                    <span id="headerCoinBalance"><?= $formattedCoins ?></span>
                    <span class="coins-topup-tag">+ Top Up</span>
                </div>

                <!-- Shopping Bag / Cart Pill Trigger -->
                <div class="cart-pill-btn" onclick="openCartDrawer()" title="View Shopping Bag">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none" stroke-width="2.2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" />
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <path d="M16 10a4 4 0 0 1-8 0" />
                    </svg>
                    <span class="cart-badge" id="headerCartCount">0</span>
                </div>

                <!-- Customer Account Status Pill (at the end of navbar on the right) -->
                <?php if ($isCustomerLoggedIn): ?>
                    <div class="store-customer-profile-pill" id="customerProfileWidget">
                        <button type="button" class="store-customer-btn" id="customerDropdownBtn"
                            onclick="toggleCustomerDropdown(event)" title="My Account">
                            <span style="display:inline-flex; align-items:center;"><svg width="15" height="15"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg></span>
                            <span
                                class="customer-first-name"><?= htmlspecialchars(explode(' ', $customerName)[0], ENT_QUOTES, 'UTF-8') ?></span>
                            <span style="font-size:9px; color:#94a3b8; margin-left:2px;">▼</span>
                        </button>
                        <div class="customer-dropdown-menu" id="customerDropdownMenu">
                            <div class="dropdown-user-header">
                                <div class="dropdown-user-name"><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="dropdown-user-email">
                                    <?= htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <a href="account.php?tab=orders" class="dropdown-menu-item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path
                                        d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
                                    </path>
                                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                                    <line x1="12" y1="22.08" x2="12" y2="12"></line>
                                </svg>
                                My Orders &amp; History
                            </a>
                            <a href="account.php?tab=profile" class="dropdown-menu-item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                                Edit Profile &amp; Address
                            </a>
                            <a href="account.php?tab=security" class="dropdown-menu-item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                Password &amp; Security
                            </a>
                            <div class="dropdown-menu-divider"></div>
                            <a href="javascript:void(0)" onclick="openTopUpModal(); closeCustomerDropdown();"
                                class="dropdown-menu-item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path d="M12 6v12M15 9.5a3.5 3.5 0 0 0-5 0c0 2 5 2 5 5a3.5 3.5 0 0 1-5 0"></path>
                                </svg>
                                Prestige Coins: <strong
                                    style="margin-left:auto; color:var(--apple-blue);"><?= $formattedCoins ?></strong>
                            </a>
                            <div class="dropdown-menu-divider"></div>
                            <button type="button" class="dropdown-menu-item" onclick="handleCustomerSignOut()"
                                style="color:#ef4444;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                    <polyline points="16 17 21 12 16 7"></polyline>
                                    <line x1="21" y1="12" x2="9" y2="12"></line>
                                </svg>
                                Sign Out
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="store-signin-link-btn" title="Sign In to Prestige Apple Store">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        <span>Sign In</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if (!empty($_SESSION['customer_just_logged_in'])): ?>
        <?php unset($_SESSION['customer_just_logged_in']); ?>
        <div class="store-container" style="padding-top: 16px; padding-bottom: 0;" id="customerWelcomeBanner">
            <div
                style="background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%); border: 1px solid #bbf7d0; border-radius: 16px; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 14px rgba(0,0,0,0.04);">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 22px;">👋</span>
                    <div>
                        <div style="font-size: 14.5px; font-weight: 700; color: #065f46;">
                            Welcome back, <?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>!
                        </div>
                        <div style="font-size: 12.5px; color: #047857; margin-top: 1px;">
                            Signed in securely with Two-Factor Verification. Your Prestige Coins (<?= $formattedCoins ?>)
                            and saved orders are active.
                        </div>
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('customerWelcomeBanner').remove()"
                    style="background: transparent; border: none; font-size: 20px; color: #059669; cursor: pointer; padding: 4px 8px; border-radius: 8px;"
                    title="Dismiss">&times;</button>
            </div>
        </div>
    <?php endif; ?>

    <script>
        window.STORE_CUSTOMER = {
            is_authenticated: <?= $isCustomerLoggedIn ? 'true' : 'false' ?>,
            id: <?= $isCustomerLoggedIn ? (int) $_SESSION['customer_id'] : 'null' ?>,
            name: <?= json_encode($customerName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            coins: <?= (float) $customerCoins ?>
        };

        function toggleCustomerDropdown(e) {
            if (e) e.stopPropagation();
            const menu = document.getElementById('customerDropdownMenu');
            if (menu) menu.classList.toggle('show');
        }

        function closeCustomerDropdown() {
            const menu = document.getElementById('customerDropdownMenu');
            if (menu) menu.classList.remove('show');
        }

        document.addEventListener('click', () => {
            closeCustomerDropdown();
        });

        async function handleCustomerSignOut() {
            if (!confirm('Are you sure you want to sign out of your Prestige account?')) return;
            try {
                sessionStorage.setItem('prestige_just_logged_out', '1');
                const res = await fetch('handler.php?action=customer_logout', { method: 'POST' });
                const data = await res.json();
                const target = (data && data.data && data.data.redirect) ? data.data.redirect : 'index.php';
                window.location.replace(target);
            } catch (e) {
                window.location.replace('index.php');
            }
        }

        // Force fresh state if retrieved from browser Back/Forward Cache (bfcache)
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                window.location.reload();
            }
        });

        // Trap back button immediately upon landing on index after logout
        document.addEventListener('DOMContentLoaded', function () {
            if (sessionStorage.getItem('prestige_just_logged_out') === '1') {
                const path = window.location.pathname.toLowerCase();
                if (path.endsWith('index.php') || path.endsWith('/') || path.endsWith('store/')) {
                    window.history.pushState(null, '', window.location.href);
                    window.addEventListener('popstate', function () {
                        window.history.pushState(null, '', window.location.href);
                    });
                }
            }
        });

        // Clear logged out trap flag when user clicks any ordinary navigation link
        document.addEventListener('click', function (e) {
            const link = e.target.closest('a');
            if (link && !link.getAttribute('onclick')?.includes('handleCustomerSignOut')) {
                sessionStorage.removeItem('prestige_just_logged_out');
            }
        });
    </script>

    <!-- ====================================================================
         GLOBAL SLIDE-OUT CART DRAWER (#cartDrawer)
         ==================================================================== -->
    <div class="cart-drawer-overlay" id="cartDrawerOverlay" onclick="closeCartDrawer(event)">
        <aside class="cart-drawer" id="cartDrawer" onclick="event.stopPropagation()">
            <!-- Drawer Header -->
            <div class="drawer-header">
                <div class="drawer-title">
                    <span>🛍️</span> Your Shopping Bag (<span id="drawerCount">0</span>)
                </div>
                <button type="button" class="drawer-close-btn" onclick="closeCartDrawer()"
                    aria-label="Close Bag">&times;</button>
            </div>

            <!-- Free Insured Shipping Progress Bar -->
            <div class="free-shipping-tracker">
                <div class="tracker-msg" id="drawerShippingMsg">
                    <span>🚚</span> Add items to qualify for Free Shipping
                </div>
                <div class="tracker-bar-bg">
                    <div class="tracker-bar-fill" id="drawerShippingFill" style="width: 0%;"></div>
                </div>
            </div>

            <!-- Line Items List -->
            <div class="drawer-items-list" id="drawerItemsList">
                <!-- Dynamically injected via store.js -->
            </div>

            <!-- Drawer Footer -->
            <div class="drawer-footer" id="drawerFooter">
                <div class="drawer-summary-line">
                    <span>Bag Subtotal</span>
                    <strong id="drawerSubtotal">₱0.00</strong>
                </div>
                <div class="drawer-summary-line">
                    <span>Insured Express Shipping</span>
                    <span id="drawerShippingFee" style="color:#059669; font-weight:700;">FREE</span>
                </div>
                <div class="drawer-summary-line total-line">
                    <span>Estimated Total</span>
                    <strong id="drawerTotal" style="color:var(--apple-dark);">₱0.00</strong>
                </div>

                <button type="button" class="drawer-checkout-btn" onclick="goToCheckout()">
                    Proceed to Checkout &rarr;
                </button>
                <a href="cart.php" class="drawer-view-bag-btn">
                    Review Bag in Detail
                </a>
            </div>
        </aside>
    </div>