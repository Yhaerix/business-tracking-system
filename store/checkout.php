<?php
/**
 * Public Customer Storefront Checkout Gateway
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
$pdo = Database::getConnection();
sync_admin_storefront_session($pdo);

if (empty($_SESSION['customer_id'])) {
    header('Location: login.php?redirect=checkout.php&reason=auth_required');
    exit;
}

// Resilient customer session verification against MySQL
$customerId = (int)$_SESSION['customer_id'];
$stmtCust = $pdo->prepare("SELECT id, name, email, phone, wallet_balance FROM customers WHERE id = :cid AND deleted_at IS NULL LIMIT 1");
$stmtCust->execute([':cid' => $customerId]);
$activeCustomer = $stmtCust->fetch(PDO::FETCH_ASSOC);

if (!$activeCustomer) {
    // If orphaned customer session (e.g. database was reset), fallback to baseline VIP Customer 1
    $stmtC1 = $pdo->query("SELECT id, name, email, phone, wallet_balance FROM customers WHERE id = 1 AND deleted_at IS NULL LIMIT 1");
    $vip = $stmtC1->fetch(PDO::FETCH_ASSOC);
    if ($vip) {
        $_SESSION['customer_id'] = 1;
        $_SESSION['customer_name'] = $vip['name'];
        $_SESSION['customer_email'] = $vip['email'];
        $_SESSION['customer_phone'] = $vip['phone'];
        $_SESSION['customer_coins'] = (float)$vip['wallet_balance'];
        $activeCustomer = $vip;
    } else {
        unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_email'], $_SESSION['customer_phone']);
        header('Location: login.php?redirect=checkout.php&reason=auth_required');
        exit;
    }
}

$customerName = $activeCustomer['name'] ?? ($_SESSION['customer_name'] ?? '');
$customerPhone = $activeCustomer['phone'] ?? ($_SESSION['customer_phone'] ?? '');
$customerEmail = $activeCustomer['email'] ?? ($_SESSION['customer_email'] ?? '');

$pageTitle = 'Secure Checkout — Prestige Apple Store';
require_once __DIR__ . '/includes/store_header.php';
?>

<div class="store-container checkout-page-wrap">
    <!-- Breadcrumb & Security Trust Banner -->
    <div class="checkout-top-header">
        <nav class="breadcrumb-nav" style="margin-bottom:0;">
            <a href="index.php">Store</a>
            <span>&rsaquo;</span>
            <a href="cart.php">Shopping Bag</a>
            <span>&rsaquo;</span>
            <span>Secure Checkout</span>
        </nav>
        <div class="checkout-ssl-badge">
            <span>🔒</span>
            <span>256-Bit Bank-Grade SSL Encrypted Checkout</span>
        </div>
    </div>

    <form id="checkoutForm" onsubmit="handlePlaceOrder(event)">
        <div class="checkout-grid">
            <!-- Left Column: Shipping & Payment Details -->
            <div class="checkout-main">
                
                <!-- Section 1: Customer Contact & Delivery Address -->
                <div class="checkout-section">
                    <div class="checkout-section-header">
                        <div class="checkout-step-num">1</div>
                        <div>
                            <h2 class="checkout-section-title">Delivery Address &amp; Contact Details</h2>
                            <p class="checkout-section-sub">Please enter the recipient information for insured courier transit</p>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="store-form-group">
                            <label class="store-form-label" for="customerName">Full Name <span style="color:var(--flame-red)">*</span></label>
                            <input type="text" id="customerName" name="customer_name" class="store-form-input" 
                                   value="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="e.g. Juan Dela Cruz" required minlength="2">
                        </div>

                        <div class="store-form-group">
                            <label class="store-form-label" for="customerPhone">Mobile Phone (for Courier SMS) <span style="color:var(--flame-red)">*</span></label>
                            <input type="tel" id="customerPhone" name="customer_phone" class="store-form-input" 
                                   value="<?= htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="e.g. 09171234567" required minlength="10" maxlength="15">
                        </div>
                    </div>

                    <div class="store-form-group">
                        <label class="store-form-label" for="customerEmail">Email Address (for Order Invoice &amp; AppleCare)</label>
                        <input type="email" id="customerEmail" name="customer_email" class="store-form-input" 
                               value="<?= htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="e.g. juan.delacruz@example.ph">
                    </div>

                    <div class="form-grid-3">
                        <div class="store-form-group">
                            <label class="store-form-label" for="province">Province / Region <span style="color:var(--flame-red)">*</span></label>
                            <input type="text" id="province" name="province" class="store-form-input" 
                                   placeholder="e.g. Metro Manila" required>
                        </div>

                        <div class="store-form-group">
                            <label class="store-form-label" for="city">City / Municipality <span style="color:var(--flame-red)">*</span></label>
                            <input type="text" id="city" name="city" class="store-form-input" 
                                   placeholder="e.g. Pasig City" required>
                        </div>

                        <div class="store-form-group">
                            <label class="store-form-label" for="barangay">Barangay <span style="color:var(--flame-red)">*</span></label>
                            <input type="text" id="barangay" name="barangay" class="store-form-input" 
                                   placeholder="e.g. San Antonio" required>
                        </div>
                    </div>

                    <div class="store-form-group">
                        <label class="store-form-label" for="streetAddress">Street Address / Unit / Building <span style="color:var(--flame-red)">*</span></label>
                        <input type="text" id="streetAddress" name="street_address" class="store-form-input" 
                               placeholder="e.g. Unit 12B, Prestige Tower, 1200 F. Ortigas Jr. Rd" required>
                    </div>

                    <div class="form-grid-2">
                        <div class="store-form-group">
                            <label class="store-form-label" for="landmark">Landmark (Optional)</label>
                            <input type="text" id="landmark" name="landmark" class="store-form-input" 
                                   placeholder="e.g. Across Emerald Avenue">
                        </div>

                        <div class="store-form-group">
                            <label class="store-form-label" for="orderNotes">Courier Delivery Notes</label>
                            <input type="text" id="orderNotes" name="order_notes" class="store-form-input" 
                                   placeholder="e.g. Please leave with building lobby guard">
                        </div>
                    </div>
                </div>

                <!-- Section 2: Courier Selection -->
                <div class="checkout-section">
                    <div class="checkout-section-header">
                        <div class="checkout-step-num">2</div>
                        <div>
                            <h2 class="checkout-section-title">Shipping &amp; Courier Service</h2>
                            <p class="checkout-section-sub">All shipments are fully insured against loss or transit damage</p>
                        </div>
                    </div>

                    <div class="courier-cards-grid">
                        <!-- Courier 1: Standard Insured -->
                        <label class="courier-option-card active" id="courierCardStandard">
                            <input type="radio" name="courier_code" value="STANDARD" checked onchange="handleCourierChange('STANDARD')">
                            <div class="courier-card-content">
                                <div class="courier-card-top">
                                    <div class="courier-name">
                                        <span>🚚</span> Standard Insured Courier
                                    </div>
                                    <span class="courier-fee-badge" id="standardFeeBadge">FREE</span>
                                </div>
                                <div class="courier-eta">Estimated Delivery: <strong>2-3 Business Days</strong></div>
                                <p class="courier-desc">Nationwide door-to-door insured delivery with live SMS and tracking number.</p>
                            </div>
                        </label>

                        <!-- Courier 2: Metro Manila Express -->
                        <label class="courier-option-card" id="courierCardExpress">
                            <input type="radio" name="courier_code" value="EXPRESS" onchange="handleCourierChange('EXPRESS')">
                            <div class="courier-card-content">
                                <div class="courier-card-top">
                                    <div class="courier-name">
                                        <span>⚡</span> Metro Manila Same-Day Express
                                    </div>
                                    <span class="courier-fee-badge fee-paid">+ ₱350.00</span>
                                </div>
                                <div class="courier-eta">Estimated Delivery: <strong>Within 4-6 Hours</strong></div>
                                <p class="courier-desc">VIP dedicated motorcycle/van courier dispatch directly from Ortigas Fulfillment Center.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Section 3: Payment Method Selection -->
                <div class="checkout-section">
                    <div class="checkout-section-header">
                        <div class="checkout-step-num">3</div>
                        <div>
                            <h2 class="checkout-section-title">Payment Method</h2>
                            <p class="checkout-section-sub">Choose instant Prestige Coins settlement or Cash on Delivery</p>
                        </div>
                    </div>

                    <div class="payment-options-grid">
                        <!-- Option A: Prestige Coins -->
                        <label class="payment-option-card active" id="paymentCardCoins">
                            <div class="payment-card-header">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <input type="radio" name="payment_method" value="PRESTIGE_COINS" checked onchange="handlePaymentChange('PRESTIGE_COINS')">
                                    <div class="payment-card-title">
                                        <span>🪙</span> Pay with Prestige Apple Coins
                                    </div>
                                </div>
                                <span class="coins-balance-tag" id="checkoutCoinsBadge">Loading balance...</span>
                            </div>

                            <!-- Sufficiency State Container -->
                            <div id="coinsSufficiencyContainer" style="margin-top:12px;">
                                <!-- Dynamically populated by store.js -->
                            </div>
                        </label>

                        <!-- Option B: Cash on Delivery -->
                        <label class="payment-option-card" id="paymentCardCod">
                            <div class="payment-card-header">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <input type="radio" name="payment_method" value="COD" onchange="handlePaymentChange('COD')">
                                    <div class="payment-card-title">
                                        <span>💵</span> Cash on Delivery (COD)
                                    </div>
                                </div>
                                <span class="cod-badge">Pay Upon Delivery</span>
                            </div>
                            <p class="payment-subtext">
                                Pay cash directly to the courier when your sealed Apple package arrives at your doorstep. You may inspect the serial number seal before releasing payment.
                            </p>
                        </label>
                    </div>
                </div>

            </div>

            <!-- Right Column: Sticky Order Summary & Place Order CTA -->
            <aside class="checkout-aside">
                <div class="checkout-summary-card">
                    <h3 class="summary-card-title" style="display:flex; justify-content:space-between; align-items:center;">
                        <span>Order Summary</span>
                        <a href="cart.php" style="font-size:12px; font-weight:600; color:var(--apple-blue); text-decoration:none;">Edit Bag</a>
                    </h3>

                    <!-- Line Items List -->
                    <div class="checkout-items-list" id="checkoutItemsList">
                        <!-- Loaded dynamically via store.js -->
                    </div>

                    <!-- Price Calculations -->
                    <div class="summary-row" style="margin-top:14px; padding-top:12px; border-top:1px solid #f0f0f2;">
                        <span>Subtotal</span>
                        <strong id="checkoutSubtotal">₱0.00</strong>
                    </div>

                    <div class="summary-row">
                        <span>Courier Shipping</span>
                        <span id="checkoutShippingFee" style="color:#059669; font-weight:700;">FREE</span>
                    </div>

                    <div class="summary-row">
                        <span>Apple Official Warranty</span>
                        <span style="color:#059669; font-weight:700;">INCLUDED</span>
                    </div>

                    <div class="summary-row total-row" style="font-size:20px;">
                        <span>Total Due</span>
                        <strong id="checkoutGrandTotal" style="color:var(--apple-dark);">₱0.00</strong>
                    </div>

                    <!-- Place Order Button -->
                    <button type="submit" class="place-order-btn" id="placeOrderBtn">
                        <span id="placeOrderBtnText">Place Order Now &rarr;</span>
                    </button>

                    <!-- Trust Accreditations -->
                    <div class="checkout-trust-box">
                        <div class="checkout-trust-line">
                            <span>🛡️</span> 1-Year Official Apple Warranty Covered
                        </div>
                        <div class="checkout-trust-line">
                            <span>📦</span> Brand New Sealed in Original Apple Box
                        </div>
                        <div class="checkout-trust-line">
                            <span>🚚</span> Insured Courier Delivery with Tracking
                        </div>
                        <div class="checkout-trust-line">
                            <span>🇵🇭</span> 100% NTC Approved for Philippine Networks
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    initCheckoutPage();
});
</script>

<?php
require_once __DIR__ . '/includes/store_footer.php';
?>
