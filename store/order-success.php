<?php
/**
 * Public Customer Storefront Order Success & Confirmation Receipt
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

$orderNumber = trim((string) ($_GET['order'] ?? ''));
if ($orderNumber === '' && isset($_SESSION['last_order']['order_number'])) {
    $orderNumber = $_SESSION['last_order']['order_number'];
}

$db = get_db();
$currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';

$stmtOrder = $db->prepare("
    SELECT id, order_number, tracking_number, customer_name, customer_phone,
           delivery_address, delivery_notes, courier_name, subtotal, delivery_fee,
           total_amount, order_status, payment_method, payment_status, created_at
    FROM orders
    WHERE order_number = :onum
    LIMIT 1
");
$stmtOrder->execute([':onum' => $orderNumber]);
$order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: index.php');
    exit;
}

// Fetch items
$stmtItems = $db->prepare("
    SELECT oi.product_name, oi.unit_price, oi.quantity, oi.line_total,
           (SELECT image_path FROM product_images WHERE product_id = oi.product_id ORDER BY is_primary DESC, id ASC LIMIT 1) AS image_path
    FROM order_items oi
    WHERE oi.order_id = :oid
");
$stmtItems->execute([':oid' => (int)$order['id']]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Order Confirmed #' . $order['order_number'];
require_once __DIR__ . '/includes/store_header.php';
?>

<div class="store-container order-success-wrap">
    <!-- Success Banner Card -->
    <div class="success-banner-card">
        <div class="success-icon-wrap">
            <svg viewBox="0 0 24 24" width="44" height="44" stroke="#059669" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>
        <h1 class="success-title">Thank You, <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?>!</h1>
        <p class="success-subtitle">
            Your Apple hardware order has been successfully placed and is now confirmed for dispatch.
        </p>
        <div class="success-order-id-pill">
            Order Reference: <strong>#<?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
    </div>

    <!-- 4-Stage Fulfillment Tracker -->
    <div class="fulfillment-tracker-card">
        <div class="tracker-steps">
            <div class="step-item completed">
                <div class="step-circle">✓</div>
                <div class="step-label">Order Confirmed</div>
                <div class="step-time"><?= date('M j, g:i A', strtotime($order['created_at'])) ?></div>
            </div>
            <div class="step-item active">
                <div class="step-circle">2</div>
                <div class="step-label">Fulfillment &amp; Serial Audit</div>
                <div class="step-time">In Progress</div>
            </div>
            <div class="step-item">
                <div class="step-circle">3</div>
                <div class="step-label">Dispatched to Courier</div>
                <div class="step-time">Estimated <?= $order['courier_name'] === 'Metro Manila Same-Day Express' ? 'Today' : 'Tomorrow' ?></div>
            </div>
            <div class="step-item">
                <div class="step-circle">4</div>
                <div class="step-label">Delivered</div>
                <div class="step-time">Insured Door-to-Door</div>
            </div>
        </div>
    </div>

    <!-- Receipt 2-Column Grid -->
    <div class="success-receipt-grid">
        <!-- Left: Itemized Hardware Receipt -->
        <div class="receipt-card">
            <h2 class="receipt-card-title">Hardware Items Ordered</h2>
            
            <div class="receipt-items-list">
                <?php foreach ($items as $item): ?>
                    <div class="receipt-item-row">
                        <div class="receipt-item-thumb">
                            <img src="../<?= htmlspecialchars($item['image_path'] ?: 'assets/images/placeholder-apple.svg', ENT_QUOTES, 'UTF-8') ?>" 
                                 alt="<?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                                 onerror="this.src='../assets/images/logo.png'">
                        </div>
                        <div class="receipt-item-info">
                            <div class="receipt-item-name"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="receipt-item-meta">
                                <span>Unit: <?= $currency . number_format((float)$item['unit_price'], 2) ?></span>
                                <span>&bull;</span>
                                <span>Qty: <strong><?= (int)$item['quantity'] ?></strong></span>
                            </div>
                        </div>
                        <div class="receipt-item-total">
                            <?= $currency . number_format((float)$item['line_total'], 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Subtotal Calculation -->
            <div style="margin-top:20px; padding-top:16px; border-top:1px solid #f0f0f2;">
                <div class="summary-row">
                    <span>Hardware Subtotal</span>
                    <strong><?= $currency . number_format((float)$order['subtotal'], 2) ?></strong>
                </div>
                <div class="summary-row">
                    <span><?= htmlspecialchars($order['courier_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= (float)$order['delivery_fee'] > 0 ? $currency . number_format((float)$order['delivery_fee'], 2) : '<span style="color:#059669; font-weight:700;">FREE</span>' ?></span>
                </div>
                <div class="summary-row total-row" style="font-size:18px;">
                    <span>Total Amount Paid / Due</span>
                    <strong style="color:var(--apple-dark);"><?= $currency . number_format((float)$order['total_amount'], 2) ?></strong>
                </div>
            </div>
        </div>

        <!-- Right: Shipping & Payment Summary -->
        <aside class="receipt-meta-column">
            <!-- Tracking Card -->
            <div class="receipt-meta-card">
                <h3 class="meta-card-title">Tracking Information</h3>
                <div style="font-size:12px; color:var(--apple-gray); margin-bottom:8px;">Live Courier Waybill Number:</div>
                <div class="tracking-number-box">
                    <span><?= htmlspecialchars($order['tracking_number'], ENT_QUOTES, 'UTF-8') ?></span>
                    <button type="button" class="copy-tracking-btn" onclick="copyTrackingCode('<?= htmlspecialchars($order['tracking_number'], ENT_QUOTES, 'UTF-8') ?>')">
                        Copy
                    </button>
                </div>
                <a href="track-order.php?order=<?= urlencode($order['order_number']) ?>" class="btn-apple-primary" style="width:100%; justify-content:center; margin-top:12px;">
                    Track Live Delivery Status &rarr;
                </a>
            </div>

            <!-- Payment Status Card -->
            <div class="receipt-meta-card">
                <h3 class="meta-card-title">Payment Settlement</h3>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <span style="font-size:13px; color:var(--apple-gray);">Method:</span>
                    <strong style="font-size:13px; color:var(--apple-dark);">
                        <?= $order['payment_method'] === 'PRESTIGE_COINS' ? '🪙 Prestige Apple Coins' : '💵 Cash on Delivery (COD)' ?>
                    </strong>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:13px; color:var(--apple-gray);">Payment Status:</span>
                    <span class="badge <?= $order['payment_status'] === 'COLLECTED' || $order['payment_status'] === 'PAID' ? 'badge-delivered' : 'badge-pending' ?>" style="font-size:11px;">
                        <?= $order['payment_status'] === 'COLLECTED' || $order['payment_status'] === 'PAID' ? 'Paid in Full' : 'Pending Collection' ?>
                    </span>
                </div>
            </div>

            <!-- Shipping Destination -->
            <div class="receipt-meta-card">
                <h3 class="meta-card-title">Shipping Destination</h3>
                <p style="font-size:13.5px; font-weight:700; color:var(--apple-dark); margin-bottom:4px;">
                    <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p style="font-size:12.5px; color:var(--apple-gray); line-height:1.4; margin-bottom:8px;">
                    <?= htmlspecialchars($order['delivery_address'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p style="font-size:12px; color:var(--apple-dark);">
                    Recipient Phone: <strong><?= htmlspecialchars($order['customer_phone'], ENT_QUOTES, 'UTF-8') ?></strong>
                </p>
                <?php if (!empty($order['delivery_notes'])): ?>
                    <div style="margin-top:8px; font-size:11.5px; color:#6b7280; background:#f8fafc; padding:8px; border-radius:6px;">
                        Delivery Note: <?= htmlspecialchars($order['delivery_notes'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Print & Continue Buttons -->
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn-apple-ghost" onclick="window.print()" style="flex:1; justify-content:center; color:var(--apple-dark);">
                    🖨️ Print Receipt
                </button>
                <a href="index.php" class="btn-apple-primary" style="flex:1; justify-content:center;">
                    Continue Shopping
                </a>
            </div>
        </aside>
    </div>
</div>

<script>
function copyTrackingCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        showToast('Tracking number copied to clipboard!', 'success');
    });
}
</script>

<?php
require_once __DIR__ . '/includes/store_footer.php';
?>
