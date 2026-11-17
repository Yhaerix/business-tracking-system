<?php
/**
 * Dedicated Shopping Bag Review Page
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 */

declare(strict_types=1);

$pageTitle = 'Review Your Apple Shopping Bag';
require_once __DIR__ . '/includes/store_header.php';
?>

<div class="store-container cart-page-wrap">
    <h1 class="cart-page-title">Review Your Shopping Bag</h1>

    <div class="cart-page-grid">
        <!-- Left: Line Items List -->
        <div class="cart-main-card">
            <!-- Free Shipping Notification Alert -->
            <div class="free-shipping-tracker" style="border-radius:12px; margin-bottom:20px;">
                <div class="tracker-msg" id="cartPageShippingMsg">
                    <span>🚚</span> Add ₱5,000.00 to qualify for Free Insured Express Delivery
                </div>
                <div class="tracker-bar-bg">
                    <div class="tracker-bar-fill" id="cartPageShippingFill" style="width:0%;"></div>
                </div>
            </div>

            <!-- Line Items Table/List Container -->
            <div id="cartPageItemsList">
                <!-- Dynamically rendered via store.js -->
            </div>

            <!-- Empty Cart Fallback -->
            <div id="cartPageEmptyState" style="display:none; text-align:center; padding:64px 20px;">
                <div style="font-size:48px; margin-bottom:12px;">🛍️</div>
                <h2 style="font-size:22px; font-weight:800; color:var(--apple-dark);">Your Apple Bag is Empty</h2>
                <p style="color:var(--apple-gray); margin:8px 0 24px;">Browse our authentic iPhone, MacBook, and iPad collections to get started.</p>
                <a href="index.php" class="btn-apple-primary">Shop Apple Hardware</a>
            </div>

            <div style="margin-top:24px; padding-top:16px; border-top:1px solid #f0f0f2; display:flex; justify-content:space-between; align-items:center;">
                <a href="index.php" style="font-size:13px; font-weight:600; color:var(--apple-blue);">&larr; Continue Shopping</a>
                <button type="button" class="btn-apple-ghost" onclick="clearFullBag()" style="color:var(--flame-red); border-color:#fecaca; font-size:12px; padding:6px 14px;">
                    Empty Shopping Bag
                </button>
            </div>
        </div>

        <!-- Right: Order Summary Card -->
        <aside class="cart-summary-card">
            <h3 class="summary-card-title">Order Summary</h3>

            <div class="summary-row">
                <span>Items Subtotal</span>
                <strong id="cartPageSubtotal">₱0.00</strong>
            </div>

            <div class="summary-row">
                <span>Insured Nationwide Delivery</span>
                <span id="cartPageShippingFee" style="color:#059669; font-weight:700;">FREE</span>
            </div>

            <div class="summary-row">
                <span>Estimated VAT / Sales Tax</span>
                <span>Included</span>
            </div>

            <div class="summary-row total-row">
                <span>Total Amount Due</span>
                <strong id="cartPageTotal" style="color:var(--apple-dark);">₱0.00</strong>
            </div>

            <!-- Action Button -->
            <button type="button" class="drawer-checkout-btn" onclick="proceedToCheckoutGateway()" style="height:50px; font-size:16px; margin-bottom:16px;">
                Proceed to Checkout &rarr;
            </button>

            <!-- Accepted Payment Trust Badges -->
            <div style="padding-top:16px; border-top:1px solid #f0f0f2;">
                <div style="font-size:11.5px; font-weight:700; text-transform:uppercase; color:var(--apple-gray); margin-bottom:8px;">
                    Guaranteed Safe &amp; Flexible Payment
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:6px; font-size:12px; color:var(--apple-dark); font-weight:600;">
                    <span style="background:#f0f0f2; padding:4px 8px; border-radius:4px;">💵 Cash on Delivery</span>
                    <span style="background:#fef3c7; color:#92400e; padding:4px 8px; border-radius:4px;">🪙 Prestige Coins</span>
                    <span style="background:#f0f0f2; padding:4px 8px; border-radius:4px;">📱 GCash / Maya</span>
                    <span style="background:#f0f0f2; padding:4px 8px; border-radius:4px;">💳 Credit / Debit</span>
                </div>
            </div>
        </aside>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    renderCartPage();
});

async function proceedToCheckoutGateway() {
    const cart = getCart();
    if (!cart.length) {
        showToast('Your shopping bag is empty!', 'error');
        return;
    }
    const isAuth = window.STORE_CUSTOMER && window.STORE_CUSTOMER.is_authenticated;
    if (!isAuth) {
        showToast('Please sign in or create an account to proceed with checkout.', 'info');
        setTimeout(() => {
            window.location.href = 'login.php?redirect=checkout.php&reason=auth_required';
        }, 400);
        return;
    }

    showToast('Redirecting to secure checkout gateway...', 'info');

    // Synchronize client bag to server session before navigation
    try {
        await fetch('handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'sync_cart', items: cart })
        });
    } catch (e) {}

    window.location.href = 'checkout.php';
}

function clearFullBag() {
    if (!confirm('Are you sure you want to empty your entire shopping bag?')) return;
    saveCart([]);
    renderCartPage();
    renderCartDrawer();
    showToast('Shopping bag emptied.', 'info');
}
</script>

<?php
require_once __DIR__ . '/includes/store_footer.php';
?>