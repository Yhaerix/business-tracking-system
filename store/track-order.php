<?php
/**
 * Public Customer Order Tracking
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 * Phase 6: Interactive Milestone Stepper & Logistics Tracker
 */

declare(strict_types=1);

$pageTitle = 'Track Your Order — Prestige Apple Store';
require_once __DIR__ . '/includes/store_header.php';
?>

<main class="store-main">
    <div class="store-container" style="max-width: 880px; padding: 40px 16px;">
        <div style="text-align: center; margin-bottom: 32px;">
            <div style="display: inline-flex; align-items: center; justify-content: center; width: 64px; height: 64px; border-radius: 50%; background: rgba(0, 113, 227, 0.1); color: var(--apple-blue); margin-bottom: 14px; box-shadow: 0 4px 16px rgba(0, 113, 227, 0.15);">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
            </div>
            <h1 style="font-size: 28px; font-weight: 800; color: var(--apple-dark); margin: 0 0 8px 0; letter-spacing: -0.02em;">
                Track Your Apple Shipment
            </h1>
            <p style="font-size: 14.5px; color: var(--apple-gray); margin: 0 auto; max-width: 560px;">
                Monitor your authentic Apple devices through insured warehouse quality checks, dispatch, and real-time courier milestones.
            </p>
        </div>

        <!-- Tracking Search Input Card (Borderless with Soft Shadow) -->
        <div style="background: #ffffff; border: none; border-radius: 20px; padding: 28px 32px; box-shadow: 0 4px 24px rgba(0,0,0,0.06); margin-bottom: 28px;">
            <div style="font-size: 13.5px; font-weight: 700; color: var(--apple-dark); margin-bottom: 12px;">
                Enter your Order Reference or Courier Tracking Code:
            </div>
            <form id="trackOrderForm" onsubmit="handleTrackSearch(event)" style="display: flex; gap: 12px; flex-wrap: wrap;">
                <input 
                    type="text" 
                    id="trackingInput" 
                    class="form-input" 
                    placeholder="e.g. PAS-2026-XXXX or Waybill #" 
                    required 
                    autocomplete="off"
                    style="flex: 1; min-width: 240px; height: 50px; font-size: 15px; border-radius: 12px; border: none; background: #f8fafc; box-shadow: inset 0 1px 3px rgba(0,0,0,0.06); padding: 0 18px; font-family: monospace; font-weight: 600;"
                >
                <button type="submit" id="trackSubmitBtn" class="btn-apple-primary" style="height: 50px; padding: 0 32px; font-size: 15px; border-radius: 12px; font-weight: 700; border: none; box-shadow: 0 4px 14px rgba(0, 113, 227, 0.3);">
                    Track Order &rarr;
                </button>
            </form>
            <?php if ($isCustomerLoggedIn): ?>
            <div style="margin-top: 14px; font-size: 13px; color: var(--apple-gray);">
                Looking for your past order receipts? Check your order history in <a href="account.php?tab=orders" style="color:var(--apple-blue); font-weight:600; text-decoration:none;">My Orders &rarr;</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Loading State Spinner -->
        <div id="trackingLoading" style="display: none; text-align: center; padding: 48px 20px;">
            <div class="store-spinner" style="display: inline-block; width: 36px; height: 36px; border: 3px solid rgba(0,113,227,0.2); border-top-color: var(--apple-blue); border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
            <p style="margin-top: 14px; font-size: 14px; color: var(--apple-gray); font-weight: 500;">
                Connecting to logistics gateway &amp; retrieving live milestone updates...
            </p>
        </div>

        <!-- Error Alert Box -->
        <div id="trackingErrorBox" style="display: none; background: #fef2f2; border: none; border-radius: 16px; padding: 24px; text-align: center; margin-bottom: 24px; box-shadow: 0 4px 18px rgba(239, 68, 68, 0.08);">
            <div style="font-size: 32px; margin-bottom: 8px;">🔍</div>
            <h3 style="font-size: 16px; font-weight: 700; color: #991b1b; margin-bottom: 4px;" id="trackingErrorTitle">Order Not Found</h3>
            <p style="font-size: 13.5px; color: #b91c1c; margin: 0 auto; max-width: 480px;" id="trackingErrorMessage">
                We couldn't find an order matching that reference code. Please verify the code on your order confirmation email or receipt.
            </p>
        </div>

        <!-- Live Dynamic Tracking Results Card -->
        <div id="trackingResultBox" style="display: none; background: #ffffff; border: none; border-radius: 20px; padding: 36px 32px; box-shadow: 0 4px 28px rgba(0,0,0,0.07); margin-bottom: 28px;">
            
            <!-- Order Header & Status Bar -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 28px; flex-wrap: wrap; gap: 16px;">
                <div>
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                        <span style="font-size: 11.5px; font-weight: 800; text-transform: uppercase; color: var(--apple-blue); letter-spacing: 0.06em;">Official Prestige Order</span>
                        <span style="font-size: 12px; color: #94a3b8;">&bull;</span>
                        <span id="orderPlacedDate" style="font-size: 12.5px; color: var(--apple-gray);">Placed on Nov 17, 2026</span>
                    </div>
                    <h2 style="font-size: 22px; font-weight: 800; color: var(--apple-dark); margin: 0; letter-spacing: -0.02em;" id="displayOrderNum">PAS-2026-0001</h2>
                    <div style="font-size: 13px; color: var(--apple-gray); margin-top: 4px;" id="estimatedDeliveryText">
                        Estimated Delivery: <strong style="color:var(--apple-dark);">Nov 19 - Nov 21, 2026</strong>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div id="statusBadgeContainer">
                        <span style="display: inline-block; padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 700; background: #ecfdf5; color: #059669;" id="displayOrderStatus">
                            In Transit — On Schedule
                        </span>
                    </div>
                    <div style="margin-top: 6px; font-size: 12px; color: var(--apple-gray);" id="displayPaymentSummary">
                        Payment: <strong>Prestige Coins</strong>
                    </div>
                </div>
            </div>

            <!-- Milestone Stepper Header -->
            <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; color: var(--apple-gray); letter-spacing: 0.06em; margin-bottom: 20px;">
                Fulfillment Milestones
            </div>

            <!-- 5-Milestone Stepper Container -->
            <div class="stepper-5-wrap" id="milestonesContainer" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; text-align: center; margin-bottom: 32px; position: relative;">
                <!-- Injected dynamically by JS -->
            </div>

            <!-- Courier Logistics Banner -->
            <div id="courierDetailsBox" style="background: #f8fafc; border: none; border-radius: 14px; padding: 20px 24px; margin-bottom: 28px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; box-shadow: 0 2px 14px rgba(0,0,0,0.04);">
                <div>
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; margin-bottom: 2px;">Assigned Courier Partner</div>
                    <div style="font-size: 15px; font-weight: 700; color: var(--apple-dark);" id="courierNameDisplay">
                        J&amp;T Express Philippines (Air Freight)
                    </div>
                    <div style="font-size: 13px; color: #475569; margin-top: 2px;">
                        Waybill Tracking #: <code style="color:var(--apple-blue); font-weight:700; font-size:13.5px;" id="courierTrackingDisplay">JT-PH-987654321</code>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <span style="display: inline-flex; align-items: center; gap: 6px; background: #fff; border: none; padding: 6px 14px; border-radius: 9999px; font-weight: 600; font-size: 12px; color: #334155; box-shadow: 0 1px 4px rgba(0,0,0,0.06);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg> Insured Transit
                    </span>
                    <a href="#" id="courierTrackingLink" target="_blank" class="btn-apple-ghost" style="padding: 7px 16px; font-size: 12.5px; border-radius: 9999px; color: var(--apple-blue); border: none; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                        Track on Courier Site &rarr;
                    </a>
                </div>
            </div>

            <!-- Payment & Security Condition Banner -->
            <div id="paymentAlertBanner" style="border-radius: 10px; padding: 14px 18px; margin-bottom: 28px; display: flex; align-items: center; gap: 12px;">
                <!-- Injected dynamically based on COD vs Coins -->
            </div>

            <!-- Recipient & Shipping Information Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; border-top: 1px solid #f1f5f9; padding-top: 24px; margin-bottom: 28px;">
                <div>
                    <div style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: var(--apple-gray); letter-spacing: 0.05em; margin-bottom: 6px;">Delivery Recipient</div>
                    <div style="font-size: 14.5px; font-weight: 700; color: var(--apple-dark);" id="recipientName">Juan Dela Cruz</div>
                    <div style="font-size: 13px; color: var(--apple-gray); margin-top: 2px;" id="recipientPhone">Phone: 0917****123</div>
                </div>
                <div>
                    <div style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: var(--apple-gray); letter-spacing: 0.05em; margin-bottom: 6px;">Insured Shipping Destination</div>
                    <div style="font-size: 13.5px; color: var(--apple-dark); line-height: 1.4;" id="recipientAddress">
                        Unit 1204, Tower B, Makati City, Metro Manila
                    </div>
                    <div style="font-size: 12px; color: var(--apple-gray); margin-top: 4px;" id="recipientNotes">
                        Notes: Standard insured delivery
                    </div>
                </div>
            </div>

            <!-- Ordered Devices Itemized Table -->
            <div style="border-top: 1px solid #f1f5f9; padding-top: 24px;">
                <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; color: var(--apple-gray); letter-spacing: 0.06em; margin-bottom: 16px;">
                    Order Package Contents
                </div>

                <div id="orderItemsList" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px;">
                    <!-- Injected dynamically by JS -->
                </div>

                <!-- Financial Summary Box -->
                <div style="background: #fafafa; border-radius: 12px; padding: 18px 24px; max-width: 360px; margin-left: auto;">
                    <div style="display: flex; justify-content: space-between; font-size: 13px; color: var(--apple-gray); margin-bottom: 8px;">
                        <span>Hardware Subtotal</span>
                        <strong style="color:var(--apple-dark);" id="summarySubtotal">₱0.00</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px; color: var(--apple-gray); margin-bottom: 8px;">
                        <span>Insured Express Delivery</span>
                        <span style="color:#059669; font-weight:700;" id="summaryDeliveryFee">FREE</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 16px; font-weight: 800; color: var(--apple-dark); padding-top: 10px; border-top: 1px solid #e5e5ea;">
                        <span>Total Amount Due</span>
                        <span id="summaryTotal" style="color:var(--apple-blue);">₱0.00</span>
                    </div>
                </div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 36px; display: flex; justify-content: center; gap: 18px; flex-wrap: wrap;">
            <a href="index.php" style="color: var(--apple-blue); font-size: 14px; text-decoration: none; font-weight: 600;">
                &larr; Return to Prestige Storefront
            </a>
            <span style="color: #cbd5e1;">|</span>
            <a href="javascript:void(0)" onclick="window.print()" style="color: var(--apple-gray); font-size: 14px; text-decoration: none; font-weight: 600;">
                🖨️ Print Tracking Summary
            </a>
        </div>
    </div>
</main>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@media (max-width: 640px) {
    .stepper-5-wrap {
        grid-template-columns: 1fr !important;
        text-align: left !important;
        gap: 16px !important;
    }
    .stepper-node-box {
        display: flex;
        align-items: center;
        gap: 14px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // If user just logged out, enforce clean bounce to store index
    if (sessionStorage.getItem('prestige_just_logged_out') === '1') {
        window.location.replace('index.php');
        return;
    }

    // Check URL parameters e.g. ?order=PAS-2026-0001 or ?tracking=PAS-2026-0001
    const urlParams = new URLSearchParams(window.location.search);
    const orderParam = urlParams.get('order') || urlParams.get('tracking') || urlParams.get('order_number') || urlParams.get('query');
    if (orderParam) {
        document.getElementById('trackingInput').value = orderParam;
        // Clean URL to prevent query parameter from persisting in browser history stack
        try {
            window.history.replaceState({}, document.title, window.location.pathname);
        } catch (e) {}
        performOrderTracking(orderParam);
    }
});

function handleTrackSearch(e) {
    e.preventDefault();
    const query = document.getElementById('trackingInput').value.trim();
    if (!query) return;
    performOrderTracking(query);
}

async function performOrderTracking(query) {
    const loadingEl = document.getElementById('trackingLoading');
    const resultBox = document.getElementById('trackingResultBox');
    const errorBox = document.getElementById('trackingErrorBox');
    const submitBtn = document.getElementById('trackSubmitBtn');

    loadingEl.style.display = 'block';
    resultBox.style.display = 'none';
    errorBox.style.display = 'none';
    if (submitBtn) submitBtn.disabled = true;

    try {
        const res = await fetch(`handler.php?action=track_order&order_number=${encodeURIComponent(query)}`);
        const json = await res.json();

        loadingEl.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;

        if (!json.success || !json.data || !json.data.order) {
            errorBox.style.display = 'block';
            document.getElementById('trackingErrorTitle').textContent = 'Order Not Found';
            document.getElementById('trackingErrorMessage').textContent = json.message || `No order matching reference "${query}" was found.`;
            return;
        }

        renderTrackingView(json.data.order);
        resultBox.style.display = 'block';
        showToast('Live milestone tracking updated.', 'success');
    } catch (err) {
        loadingEl.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;
        errorBox.style.display = 'block';
        document.getElementById('trackingErrorTitle').textContent = 'Network / Tracking Service Error';
        document.getElementById('trackingErrorMessage').textContent = 'Unable to reach the tracking gateway. Please verify your connection and try again.';
    }
}

function renderTrackingView(order) {
    document.getElementById('displayOrderNum').textContent = order.order_number;
    document.getElementById('orderPlacedDate').textContent = `Placed on ${order.formatted_created_at}`;
    document.getElementById('estimatedDeliveryText').innerHTML = `Estimated Delivery: <strong style="color:var(--apple-dark);">${order.estimated_delivery}</strong>`;

    // Status Badge
    const badgeEl = document.getElementById('displayOrderStatus');
    badgeEl.textContent = order.status_badge_text;
    if (order.is_cancelled) {
        badgeEl.style.background = '#fef2f2';
        badgeEl.style.color = '#ef4444';
    } else if (order.is_delivered) {
        badgeEl.style.background = '#ecfdf5';
        badgeEl.style.color = '#059669';
    } else {
        badgeEl.style.background = '#eff6ff';
        badgeEl.style.color = '#0284c7';
    }

    document.getElementById('displayPaymentSummary').innerHTML = `Payment: <strong>${order.payment_method_label}</strong>`;

    // Milestones 5-Step Stepper
    const milestonesEl = document.getElementById('milestonesContainer');
    let stepsHtml = '';
    order.milestones.forEach((m) => {
        let nodeBg = '#e2e8f0';
        let nodeColor = '#64748b';
        let nodeIcon = m.step;
        let titleColor = '#64748b';

        if (m.is_complete) {
            nodeBg = '#22c55e';
            nodeColor = '#ffffff';
            nodeIcon = '✓';
            titleColor = 'var(--apple-dark)';
        }
        if (m.is_current) {
            nodeBg = 'var(--apple-blue)';
            nodeColor = '#ffffff';
            nodeIcon = (m.step === 3 ? '🚚' : (m.step === 4 ? '🛵' : (m.step === 5 ? '★' : '●')));
            titleColor = 'var(--apple-blue)';
        }

        stepsHtml += `
            <div class="stepper-node-box" style="position: relative;">
                <div style="width: 38px; height: 38px; border-radius: 50%; background: ${nodeBg}; color: ${nodeColor}; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: 800; font-size: 14px; box-shadow: ${m.is_current ? '0 0 0 4px rgba(0,113,227,0.2)' : 'none'};">
                    ${nodeIcon}
                </div>
                <div>
                    <div style="font-weight: 700; font-size: 12.5px; color: ${titleColor};">${m.title}</div>
                    <div style="font-size: 11px; color: var(--apple-gray); margin-top: 2px;">${m.timestamp}</div>
                    <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">${m.description}</div>
                </div>
            </div>
        `;
    });
    milestonesEl.innerHTML = stepsHtml;

    // Courier Logistics
    document.getElementById('courierNameDisplay').textContent = order.courier_name;
    document.getElementById('courierTrackingDisplay').textContent = order.tracking_number;
    const linkEl = document.getElementById('courierTrackingLink');
    if (order.tracking_url && order.tracking_url !== '#') {
        linkEl.href = order.tracking_url;
        linkEl.style.display = 'inline-flex';
    } else {
        linkEl.style.display = 'none';
    }

    // Payment Banner
    const payBanner = document.getElementById('paymentAlertBanner');
    if (order.payment_method === 'PRESTIGE_COINS') {
        payBanner.style.background = '#ecfdf5';
        payBanner.style.border = 'none';
        payBanner.style.boxShadow = '0 2px 12px rgba(16, 185, 129, 0.12)';
        payBanner.innerHTML = `
            <span style="font-size: 22px;">🪙</span>
            <div>
                <strong style="color:#065f46; font-size:13.5px;">Paid in Full via Prestige Coins (0.00 Balance Due)</strong>
                <p style="margin: 2px 0 0 0; font-size: 12.5px; color: #047857;">
                    Your account coins covered this order completely. No cash collection required upon rider delivery.
                </p>
            </div>
        `;
    } else {
        payBanner.style.background = '#fffbeb';
        payBanner.style.border = 'none';
        payBanner.style.boxShadow = '0 2px 12px rgba(245, 158, 11, 0.15)';
        payBanner.innerHTML = `
            <span style="font-size: 22px;">💵</span>
            <div>
                <strong style="color:#92400e; font-size:13.5px;">Cash on Delivery (COD) — Amount to Collect: ${order.formatted_total}</strong>
                <p style="margin: 2px 0 0 0; font-size: 12.5px; color: #b45309;">
                    Please prepare exact cash for the delivery rider upon package arrival. An official printed receipt will be issued.
                </p>
            </div>
        `;
    }

    // Recipient & Address
    document.getElementById('recipientName').textContent = order.customer_name;
    document.getElementById('recipientPhone').textContent = `Mobile: ${order.customer_phone_masked}`;
    document.getElementById('recipientAddress').textContent = order.delivery_address;
    document.getElementById('recipientNotes').textContent = `Delivery Instructions: ${order.delivery_notes}`;

    // Itemized Hardware Table
    const itemsEl = document.getElementById('orderItemsList');
    let itemsHtml = '';
    order.items.forEach(it => {
        const imgPath = it.primary_image.startsWith('../') ? it.primary_image : `../${it.primary_image}`;
        itemsHtml += `
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 18px; background: #ffffff; border: none; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <img src="${imgPath}" alt="${it.product_name}" style="width: 52px; height: 52px; object-fit: contain; border-radius: 8px; background: #f8fafc; padding: 4px; border: none; box-shadow: 0 1px 4px rgba(0,0,0,0.05);" onerror="this.src='../assets/images/logo.png'">
                    <div>
                        <div style="font-weight: 700; font-size: 13.5px; color: var(--apple-dark);">${it.product_name}</div>
                        <div style="font-size: 12px; color: var(--apple-gray);">
                            ${it.storage} &bull; ${it.color} &bull; <span style="color:#059669; font-weight:600;">${it.warranty}</span>
                        </div>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-weight: 700; font-size: 14px; color: var(--apple-dark);">${it.formatted_line_total}</div>
                    <div style="font-size: 12px; color: var(--apple-gray);">Qty: ${it.quantity} &times; ${it.formatted_unit_price}</div>
                </div>
            </div>
        `;
    });
    itemsEl.innerHTML = itemsHtml;

    // Financial Summary
    document.getElementById('summarySubtotal').textContent = order.formatted_subtotal;
    document.getElementById('summaryDeliveryFee').textContent = order.formatted_delivery_fee;
    document.getElementById('summaryTotal').textContent = order.formatted_total;
}
</script>

<?php
require_once __DIR__ . '/includes/store_footer.php';
?>
