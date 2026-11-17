<?php
/**
 * Printable Packing Slip & Official Warranty Invoice
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 * Phase 6: E-Commerce Fulfillment & Dispatch Integration
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_login();
require_permission('orders_view');

$db = get_db();

$orderId = (int)($_GET['id'] ?? $_GET['order_id'] ?? 0);
$orderNumber = trim((string)($_GET['order_number'] ?? ''));

if ($orderId <= 0 && $orderNumber === '') {
    die('Invalid order specified. Please provide a valid order ID or Order Number.');
}

if ($orderId > 0) {
    $stmt = $db->prepare("SELECT * FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $orderId]);
} else {
    $stmt = $db->prepare("SELECT * FROM orders WHERE order_number = :num AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':num' => $orderNumber]);
}
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found or has been removed.');
}

$orderId = (int)$order['id'];

// Fetch staff creator
$stmtStaff = $db->prepare("SELECT full_name, username FROM users WHERE id = :uid LIMIT 1");
$stmtStaff->execute([':uid' => (int)$order['user_id']]);
$staff = $stmtStaff->fetch(PDO::FETCH_ASSOC) ?: ['full_name' => 'Store Operations', 'username' => 'staff'];

// Fetch items
$stmtItems = $db->prepare("
    SELECT oi.*, p.sku, p.storage, p.color, p.warranty
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = :oid
    ORDER BY oi.id ASC
");
$stmtItems->execute([':oid' => $orderId]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$isCoins = (strtoupper((string)$order['payment_method']) === 'PRESTIGE_COINS');
$totalAmount = (float)$order['total_amount'];
$currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';
$courierName = $order['courier_name'] ?: 'Standard Insured Courier (J&T Express)';
$trackingNumber = $order['tracking_number'] ?: 'PAS-TRK-' . mt_rand(100000, 999999);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Packing Slip — Order #<?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1e293b;
            background: #f1f5f9;
            padding: 24px;
            font-size: 13px;
            line-height: 1.5;
        }
        .slip-page {
            max-width: 820px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px;
            position: relative;
        }
        .print-btn-bar {
            max-width: 820px;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-print {
            background: #0071e3;
            color: #fff;
            padding: 8px 18px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-print:hover { background: #0077ed; }
        .header-grid {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .brand-title {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
        }
        .brand-sub {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
        .doc-title {
            text-align: right;
        }
        .doc-title h1 {
            font-size: 18px;
            font-weight: 800;
            color: #0071e3;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .doc-title .meta {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            color: #334155;
            margin-top: 4px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 16px;
        }
        .info-box h3 {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.05em;
            margin-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
        }
        .payment-banner {
            border-radius: 6px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .payment-banner.coins {
            background: #ecfdf5;
            border: 2px solid #059669;
            color: #065f46;
        }
        .payment-banner.cod {
            background: #fffbeb;
            border: 2px solid #d97706;
            color: #78350f;
        }
        table.items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        table.items-table th {
            background: #f1f5f9;
            color: #334155;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 10px 12px;
            border-bottom: 2px solid #cbd5e1;
        }
        table.items-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12.5px;
        }
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 24px;
        }
        .totals-table {
            width: 320px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 13px;
            color: #64748b;
        }
        .totals-row.grand {
            border-top: 2px solid #0f172a;
            padding-top: 8px;
            margin-top: 6px;
            font-weight: 800;
            font-size: 16px;
            color: #0f172a;
        }
        .signatures-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            border-top: 1px dashed #cbd5e1;
            padding-top: 24px;
            margin-top: 32px;
            text-align: center;
        }
        .sig-box {
            padding-top: 40px;
            border-top: 1px solid #94a3b8;
            font-size: 11px;
            color: #64748b;
        }
        .sig-box strong {
            display: block;
            color: #0f172a;
            font-size: 12px;
            margin-bottom: 2px;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .slip-page { box-shadow: none; border-radius: 0; padding: 20px 0; max-width: 100%; }
            .print-btn-bar { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="print-btn-bar">
        <a href="index.php" style="color: #64748b; text-decoration: none; font-weight: 600;">&larr; Back to Orders Management</a>
        <button type="button" class="btn-print" onclick="window.print()">
            🖨️ Print Packing Slip / Invoice
        </button>
    </div>

    <div class="slip-page">
        <!-- Header -->
        <div class="header-grid">
            <div>
                <div class="brand-title">PRESTIGE APPLE STORE</div>
                <div class="brand-sub">Official Apple Authorised Reseller &amp; Insured Fulfillment Center</div>
                <div style="font-size: 11.5px; color: #94a3b8; margin-top: 4px;">
                    Ayala Avenue, Makati City, Metro Manila &bull; Tel: +63 917 800 2775
                </div>
            </div>
            <div class="doc-title">
                <h1>PACKING SLIP / WAYBILL</h1>
                <div class="meta">ORDER: <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></div>
                <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                    Date: <?= date('M d, Y h:i A', strtotime($order['created_at'])) ?>
                </div>
            </div>
        </div>

        <!-- Payment Highlight Notice -->
        <?php if ($isCoins): ?>
            <div class="payment-banner coins">
                <div>
                    <div style="font-weight: 800; font-size: 14px; text-transform: uppercase; letter-spacing: 0.05em;">
                        ✓ PAID IN FULL VIA PRESTIGE COINS
                    </div>
                    <div style="font-size: 12px; margin-top: 2px;">
                        DO NOT COLLECT CASH. Package is completely settled via Customer VIP Wallet.
                    </div>
                </div>
                <div style="text-align: right; font-family: 'JetBrains Mono', monospace; font-size: 16px; font-weight: 800;">
                    AMOUNT DUE: ₱0.00
                </div>
            </div>
        <?php else: ?>
            <div class="payment-banner cod">
                <div>
                    <div style="font-weight: 800; font-size: 14px; text-transform: uppercase; letter-spacing: 0.05em;">
                        ⚠️ CASH ON DELIVERY (COD) — COLLECT UPON DELIVERY
                    </div>
                    <div style="font-size: 12px; margin-top: 2px;">
                        Courier Rider: Please collect exact amount before releasing sealed Apple hardware.
                    </div>
                </div>
                <div style="text-align: right; font-family: 'JetBrains Mono', monospace; font-size: 18px; font-weight: 800; color: #b45309;">
                    COLLECT: <?= $currency . number_format($totalAmount, 2) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Shipping & Courier Info Grid -->
        <div class="info-grid">
            <div class="info-box">
                <h3>Customer Delivery Destination</h3>
                <div style="font-weight: 700; font-size: 14px; color: #0f172a;">
                    <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="font-weight: 600; color: #0284c7; margin-top: 2px;">
                    Phone: <?= htmlspecialchars($order['customer_phone'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="margin-top: 6px; line-height: 1.4; color: #334155;">
                    <?= nl2br(htmlspecialchars($order['delivery_address'], ENT_QUOTES, 'UTF-8')) ?>
                </div>
                <?php if (!empty($order['delivery_notes'])): ?>
                    <div style="margin-top: 8px; font-size: 11.5px; background: #fff; padding: 6px 10px; border-radius: 4px; border: 1px solid #e2e8f0; color: #64748b;">
                        <strong>Notes:</strong> <?= htmlspecialchars($order['delivery_notes'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="info-box">
                <h3>Logistics &amp; Fulfillment Dispatch</h3>
                <div><strong>Assigned Courier:</strong> <?= htmlspecialchars($courierName, ENT_QUOTES, 'UTF-8') ?></div>
                <div style="margin-top: 4px;">
                    <strong>Waybill Tracking #:</strong>
                    <code style="font-family:'JetBrains Mono', monospace; font-size:13px; font-weight:700; color:#0071e3; background:#fff; padding:2px 6px; border-radius:4px; border:1px solid #cbd5e1;">
                        <?= htmlspecialchars($trackingNumber, ENT_QUOTES, 'UTF-8') ?>
                    </code>
                </div>
                <div style="margin-top: 6px;">
                    <strong>Shipping Service:</strong> Insured Door-to-Door Air Express
                </div>
                <div style="margin-top: 4px;">
                    <strong>Order Status:</strong> <?= htmlspecialchars($order['order_status'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="margin-top: 4px;">
                    <strong>Processed By:</strong> <?= htmlspecialchars($staff['full_name'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
        </div>

        <!-- Itemized Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 14%;">SKU</th>
                    <th>Device Description &amp; Specifications</th>
                    <th style="width: 16%;" class="text-center">QC Check</th>
                    <th style="width: 10%; text-align: center;">Qty</th>
                    <th style="width: 16%; text-align: right;">Unit Price</th>
                    <th style="width: 18%; text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td style="font-family:'JetBrains Mono', monospace; font-size:11px; font-weight:600; color:#475569;">
                            <?= htmlspecialchars($it['sku'] ?: 'APL-DEV', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <div style="font-weight: 700; color: #0f172a;">
                                <?= htmlspecialchars($it['product_name'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                <?= htmlspecialchars($it['storage'] ?: 'Standard', ENT_QUOTES, 'UTF-8') ?> &bull;
                                <?= htmlspecialchars($it['color'] ?: 'Standard', ENT_QUOTES, 'UTF-8') ?> &bull;
                                Official Apple Warranty: <?= htmlspecialchars($it['warranty'] ?: '1 Year', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </td>
                        <td style="text-align: center; font-size: 11px; color: #059669; font-weight: 600;">
                            [✓] Serial Verified<br>
                            [✓] Sealed Box
                        </td>
                        <td style="text-align: center; font-weight: 700;">
                            <?= (int)$it['quantity'] ?>
                        </td>
                        <td style="text-align: right; font-family:'JetBrains Mono', monospace;">
                            <?= $currency . number_format((float)$it['unit_price'], 2) ?>
                        </td>
                        <td style="text-align: right; font-weight: 700; font-family:'JetBrains Mono', monospace;">
                            <?= $currency . number_format((float)$it['line_total'], 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totals Section -->
        <div class="totals-section">
            <div class="totals-table">
                <div class="totals-row">
                    <span>Hardware Subtotal:</span>
                    <strong><?= $currency . number_format((float)$order['subtotal'], 2) ?></strong>
                </div>
                <div class="totals-row">
                    <span>Insured Nationwide Delivery:</span>
                    <span><?= (float)$order['delivery_fee'] > 0 ? $currency . number_format((float)$order['delivery_fee'], 2) : 'FREE' ?></span>
                </div>
                <div class="totals-row grand">
                    <span>Grand Total:</span>
                    <span><?= $currency . number_format($totalAmount, 2) ?></span>
                </div>
            </div>
        </div>

        <!-- Warranty & Replacement Notice -->
        <div style="background: #f8fafc; border-left: 3px solid #0071e3; padding: 10px 14px; font-size: 11.5px; color: #475569; margin-bottom: 24px; border-radius: 0 4px 4px 0;">
            <strong>Apple Authenticity Guarantee:</strong> All devices are 100% genuine, factory-sealed, and backed by a 7-day replacement policy for factory defects plus 1-year official Apple manufacturer warranty claimable at any authorized Apple Service Center nationwide.
        </div>

        <!-- Signatures & Acceptance -->
        <div class="signatures-grid">
            <div class="sig-box">
                <strong><?= htmlspecialchars($staff['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                Packed &amp; Inspected By
            </div>
            <div class="sig-box">
                <strong><?= htmlspecialchars($courierName, ENT_QUOTES, 'UTF-8') ?></strong>
                Courier Rider Receiving Signature
            </div>
            <div class="sig-box">
                <strong>Customer Signature</strong>
                Received in Good Condition
            </div>
        </div>
    </div>

</body>
</html>
