<?php
/**
 * Customer Account, Profile & Order History Hub
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 * Apple ID Style Customer Portal
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
sync_admin_storefront_session();

// 1. Session Auth Guard - Redirect Guests to Login
if (empty($_SESSION['customer_id'])) {
    $redirectUrl = 'account.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    header('Location: login.php?redirect=' . urlencode($redirectUrl) . '&reason=auth_required');
    exit;
}

$pageTitle = 'My Apple Account & Orders';
require_once __DIR__ . '/includes/store_header.php';

$activeTab = $_GET['tab'] ?? 'orders';
$validTabs = ['orders', 'profile', 'address', 'security', 'wallet'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'orders';
}
?>

<main class="store-main account-main">
    <div class="store-container account-container">

        <!-- 1. Apple ID Account Header Card -->
        <section class="account-hero-card">
            <div class="account-hero-inner">
                <div class="account-avatar-wrap">
                    <div class="account-avatar-circle">
                        <span><?= htmlspecialchars(strtoupper(substr($customerName, 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
                <div class="account-hero-info">
                    <div class="account-badge-line">
                        <span class="account-vip-tag">⭐ Prestige Apple Member</span>
                        <span class="account-id-chip">Customer ID: #<?= (int)$_SESSION['customer_id'] ?></span>
                    </div>
                    <h1 class="account-customer-name" id="heroCustomerName"><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></h1>
                    <div class="account-meta-line">
                        <span>✉️ <span id="heroCustomerEmail"><?= htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8') ?></span></span>
                        <span>&bull;</span>
                        <span>📱 <span id="heroCustomerPhone"><?= htmlspecialchars($_SESSION['customer_phone'] ?? 'Phone not set', ENT_QUOTES, 'UTF-8') ?></span></span>
                    </div>
                </div>
                <div class="account-hero-wallet">
                    <div class="account-wallet-box" onclick="openTopUpModal()" title="View and Top Up Balance">
                        <div class="wallet-subtext">Prestige Coins Balance</div>
                        <div class="wallet-val" id="accountWalletVal"><?= $formattedCoins ?></div>
                        <button type="button" class="btn-wallet-topup">+ Top Up Coins</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- 2. Dual-Column Navigation Layout -->
        <div class="account-layout-grid">
            
            <!-- Sidebar Navigation -->
            <aside class="account-sidebar">
                <nav class="account-nav-menu">
                    <button type="button" class="account-nav-item <?= $activeTab === 'orders' ? 'active' : '' ?>" onclick="switchAccountTab('orders', this)">
                        <span class="nav-icon">📦</span>
                        <span class="nav-label">My Orders &amp; History</span>
                        <span class="nav-pill-count" id="navOrderCount">...</span>
                    </button>
                    <button type="button" class="account-nav-item <?= $activeTab === 'profile' ? 'active' : '' ?>" onclick="switchAccountTab('profile', this)">
                        <span class="nav-icon" style="display:inline-flex; align-items:center;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                        <span class="nav-label">Personal Profile</span>
                    </button>
                    <button type="button" class="account-nav-item <?= $activeTab === 'address' ? 'active' : '' ?>" onclick="switchAccountTab('address', this)">
                        <span class="nav-icon" style="display:inline-flex; align-items:center;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></span>
                        <span class="nav-label">Delivery Addresses</span>
                    </button>
                    <button type="button" class="account-nav-item <?= $activeTab === 'security' ? 'active' : '' ?>" onclick="switchAccountTab('security', this)">
                        <span class="nav-icon" style="display:inline-flex; align-items:center;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></span>
                        <span class="nav-label">Password &amp; Security</span>
                    </button>
                    <button type="button" class="account-nav-item <?= $activeTab === 'wallet' ? 'active' : '' ?>" onclick="switchAccountTab('wallet', this)">
                        <span class="nav-icon" style="display:inline-flex; align-items:center;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v12M15 9.5a3.5 3.5 0 0 0-5 0c0 2 5 2 5 5a3.5 3.5 0 0 1-5 0"></path></svg></span>
                        <span class="nav-label">Prestige Coins</span>
                    </button>
                    <div class="account-nav-divider"></div>
                    <button type="button" class="account-nav-item text-danger" onclick="handleCustomerSignOut()">
                        <span class="nav-icon" style="display:inline-flex; align-items:center;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg></span>
                        <span class="nav-label">Sign Out</span>
                    </button>
                </nav>
            </aside>

            <!-- Main Tab Panels Container -->
            <section class="account-content-area">

                <!-- ==========================================================
                     TAB 1: MY ORDERS & HISTORY
                     ========================================================== -->
                <div class="account-tab-panel <?= $activeTab === 'orders' ? 'active' : '' ?>" id="tab-orders">
                    <div class="tab-header-row">
                        <div>
                            <h2 class="tab-title">Order History &amp; Shipments</h2>
                            <p class="tab-subtitle">Monitor quality checks, real-time courier dispatch, and past purchase receipts.</p>
                        </div>
                        <!-- Order Sub-Filter Tabs -->
                        <div class="order-filter-bar">
                            <button type="button" class="order-filter-btn active" onclick="filterOrderHistory('all', this)">All</button>
                            <button type="button" class="order-filter-btn" onclick="filterOrderHistory('active', this)">Active Shipments</button>
                            <button type="button" class="order-filter-btn" onclick="filterOrderHistory('delivered', this)">Delivered</button>
                            <button type="button" class="order-filter-btn" onclick="filterOrderHistory('cancelled', this)">Cancelled</button>
                        </div>
                    </div>

                    <!-- Orders List Container -->
                    <div id="accountOrdersLoading" class="account-loading-spinner">
                        <div class="store-spinner"></div>
                        <p>Loading your Apple purchase history...</p>
                    </div>

                    <div id="accountOrdersList" class="orders-cards-list">
                        <!-- Populated dynamically via JS -->
                    </div>

                    <!-- Empty State -->
                    <div id="accountOrdersEmpty" class="orders-empty-card" style="display: none;">
                        <div class="empty-icon-wrap">🛍️</div>
                        <h3>No Orders Found</h3>
                        <p>You haven't placed any Apple orders matching this filter yet.</p>
                        <a href="index.php" class="btn-apple-primary" style="margin-top: 16px;">
                            Explore Genuine Apple Devices &rarr;
                        </a>
                    </div>
                </div>

                <!-- ==========================================================
                     TAB 2: PERSONAL PROFILE
                     ========================================================== -->
                <div class="account-tab-panel <?= $activeTab === 'profile' ? 'active' : '' ?>" id="tab-profile">
                    <div class="tab-header-row">
                        <div>
                            <h2 class="tab-title">Personal Profile</h2>
                            <p class="tab-subtitle">Manage your personal details used for warranty registration and notifications.</p>
                        </div>
                    </div>

                    <div class="account-card-box">
                        <form id="profileForm" onsubmit="handleProfileSubmit(event)">
                            <div class="form-row-2">
                                <div class="form-group">
                                    <label for="profName" class="form-label">Full Name <span class="required">*</span></label>
                                    <input type="text" id="profName" class="form-input" required autocomplete="name" maxlength="100">
                                    <div class="field-hint">Your official legal name for Apple warranty claims.</div>
                                </div>
                                <div class="form-group">
                                    <label for="profPhone" class="form-label">Contact Phone <span class="required">*</span></label>
                                    <input type="tel" id="profPhone" class="form-input" required autocomplete="tel" placeholder="0917 123 4567">
                                    <div class="field-hint">Used by our courier rider for delivery coordination.</div>
                                </div>
                            </div>

                            <div class="form-group" style="margin-top: 16px;">
                                <label for="profEmail" class="form-label">Apple ID / Email Address</label>
                                <input type="email" id="profEmail" class="form-input" readonly disabled style="background:#f8fafc; color:#64748b; cursor:not-allowed;">
                                <div class="field-hint">Your unique account identifier. Contact customer care if you need to transfer email.</div>
                            </div>

                            <div class="form-actions-row">
                                <button type="submit" id="btnSaveProfile" class="btn-apple-primary">
                                    Save Changes
                                </button>
                                <span id="profileSaveMsg" class="save-status-msg"></span>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ==========================================================
                     TAB 3: DELIVERY ADDRESSES
                     ========================================================== -->
                <div class="account-tab-panel <?= $activeTab === 'address' ? 'active' : '' ?>" id="tab-address">
                    <div class="tab-header-row">
                        <div>
                            <h2 class="tab-title">Delivery Address</h2>
                            <p class="tab-subtitle">Default destination for insured door-to-door courier shipments.</p>
                        </div>
                    </div>

                    <div class="account-card-box">
                        <form id="addressForm" onsubmit="handleAddressSubmit(event)">
                            <div class="form-group">
                                <label for="addrLine1" class="form-label">Street Address &amp; House/Unit # <span class="required">*</span></label>
                                <input type="text" id="addrLine1" class="form-input" required placeholder="e.g. Unit 2401 High Street South, 26th Street" maxlength="255">
                            </div>

                            <div class="form-group" style="margin-top: 14px;">
                                <label for="addrLine2" class="form-label">Barangay / Building / Landmark (Optional)</label>
                                <input type="text" id="addrLine2" class="form-input" placeholder="e.g. Bonifacio Global City, near Serendra" maxlength="255">
                            </div>

                            <div class="form-row-3" style="margin-top: 14px;">
                                <div class="form-group">
                                    <label for="addrCity" class="form-label">City / Municipality <span class="required">*</span></label>
                                    <input type="text" id="addrCity" class="form-input" required placeholder="e.g. Taguig" maxlength="100">
                                </div>
                                <div class="form-group">
                                    <label for="addrProvince" class="form-label">Province / Region <span class="required">*</span></label>
                                    <input type="text" id="addrProvince" class="form-input" required placeholder="e.g. Metro Manila" maxlength="100">
                                </div>
                                <div class="form-group">
                                    <label for="addrPostal" class="form-label">Postal Code</label>
                                    <input type="text" id="addrPostal" class="form-input" placeholder="e.g. 1634" maxlength="10">
                                </div>
                            </div>

                            <div class="form-actions-row">
                                <button type="submit" id="btnSaveAddress" class="btn-apple-primary">
                                    Save Default Address
                                </button>
                                <span id="addressSaveMsg" class="save-status-msg"></span>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ==========================================================
                     TAB 4: PASSWORD & SECURITY
                     ========================================================== -->
                <div class="account-tab-panel <?= $activeTab === 'security' ? 'active' : '' ?>" id="tab-security">
                    <div class="tab-header-row">
                        <div>
                            <h2 class="tab-title">Password &amp; Account Security</h2>
                            <p class="tab-subtitle">Update your password to protect your Prestige Coins and order history.</p>
                        </div>
                    </div>

                    <div class="account-card-box">
                        <form id="passwordForm" onsubmit="handlePasswordSubmit(event)">
                            <div class="form-group">
                                <label for="currPass" class="form-label">Current Password <span class="required">*</span></label>
                                <input type="password" id="currPass" class="form-input" required autocomplete="current-password" placeholder="Enter current account password">
                            </div>

                            <div class="form-row-2" style="margin-top: 16px;">
                                <div class="form-group">
                                    <label for="newPass" class="form-label">New Password <span class="required">*</span></label>
                                    <input type="password" id="newPass" class="form-input" required autocomplete="new-password" minlength="6" placeholder="At least 6 characters">
                                    <div class="field-hint">Must be at least 6 characters long.</div>
                                </div>
                                <div class="form-group">
                                    <label for="confPass" class="form-label">Confirm New Password <span class="required">*</span></label>
                                    <input type="password" id="confPass" class="form-input" required autocomplete="new-password" minlength="6" placeholder="Repeat new password">
                                </div>
                            </div>

                            <div class="form-actions-row">
                                <button type="submit" id="btnSavePassword" class="btn-apple-primary">
                                    Update Password
                                </button>
                                <span id="passwordSaveMsg" class="save-status-msg"></span>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ==========================================================
                     TAB 5: PRESTIGE COINS WALLET
                     ========================================================== -->
                <div class="account-tab-panel <?= $activeTab === 'wallet' ? 'active' : '' ?>" id="tab-wallet">
                    <div class="tab-header-row">
                        <div>
                            <h2 class="tab-title">Prestige Coins Wallet</h2>
                            <p class="tab-subtitle">1 Prestige Coin = <?= $currency . number_format($coinRate ?? 1.00, 2) ?> Philippine Peso. Instant 1-click checkout with zero payment processing fees.</p>
                        </div>
                    </div>

                    <div class="account-wallet-banner-card">
                        <div class="wallet-banner-content">
                            <span class="wallet-banner-pill">Official Store Wallet</span>
                            <div class="wallet-banner-amount" id="tabWalletBalance"><?= $formattedCoins ?></div>
                            <p class="wallet-banner-desc">
                                Spend your coins on any genuine Apple device or accessory. If your balance is short during checkout, you can top up the exact difference in 1 click via GCash or Bank Transfer.
                            </p>
                            <div style="display:flex; gap:12px; margin-top:20px; flex-wrap:wrap;">
                                <button type="button" class="btn-apple-primary" onclick="openTopUpModal()">
                                    + Top Up Prestige Coins Now
                                </button>
                                <a href="index.php" class="btn-apple-ghost" style="color:var(--apple-dark); border-color:#cbd5e1;">
                                    Browse Store Catalog &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </section>
        </div>
    </div>
</main>

<!-- Detailed Order Receipt Modal -->
<div class="store-modal-overlay" id="orderReceiptModal" onclick="closeOrderReceiptModal(event)">
    <div class="store-modal-box receipt-modal-box" onclick="event.stopPropagation()">
        <div class="receipt-modal-header">
            <div>
                <span class="receipt-modal-badge">Official Order Breakdown</span>
                <h3 class="receipt-modal-title" id="receiptModalOrderNum">PAS-2026-XXXXX</h3>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeOrderReceiptModal()">&times;</button>
        </div>
        <div class="receipt-modal-body" id="receiptModalBody">
            <!-- Dynamically populated -->
        </div>
        <div class="receipt-modal-footer">
            <button type="button" class="btn-apple-ghost" onclick="closeOrderReceiptModal()" style="color:var(--apple-dark);">Close</button>
            <a href="javascript:void(0)" id="receiptModalTrackBtn" class="btn-apple-primary">Track This Shipment &rarr;</a>
        </div>
    </div>
</div>

<script>
window.ACCOUNT_PAGE_DATA = {
    customer_id: <?= (int)$_SESSION['customer_id'] ?>,
    initial_tab: <?= json_encode($activeTab) ?>
};
</script>

<?php
require_once __DIR__ . '/includes/store_footer.php';
?>
