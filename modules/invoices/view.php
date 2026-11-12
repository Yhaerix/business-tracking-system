<?php
/**
 * Invoice & Receipt Printable Document View
 * Small Business Sales and Expense Tracking System
 * Reference: docs/UI_DESIGN_SPEC.md
 */

declare(strict_types=1);

$pageTitle = 'View Document';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_permission('invoices_view');

$canManage = has_permission('invoices_manage'); // Admin can void

$invoiceId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : null;
$orderId = isset($_GET['order_id']) && is_numeric($_GET['order_id']) ? (int) $_GET['order_id'] : null;
$initialMode = isset($_GET['mode']) && $_GET['mode'] === 'receipt' ? 'receipt' : 'a4';

if ($invoiceId === null && $orderId === null) {
    header('Location: ' . BASE_URL . '/modules/invoices/');
    exit;
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- Ensure Print CSS is loaded directly for document rendering -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">

<style>
/* Document Viewer Specific Enhancements */
.stamp-badge {
    position: absolute;
    top: 48px;
    right: 260px;
    padding: 8px 18px;
    text-transform: uppercase;
    font-weight: 800;
    font-size: 20px;
    letter-spacing: 2px;
    border-radius: 6px;
    transform: rotate(-10deg);
    pointer-events: none;
    display: none;
    z-index: 10;
}
.stamp-paid {
    border: 3.5px dashed #16a34a;
    color: #16a34a;
    background: rgba(240, 253, 244, 0.85);
}
.stamp-void {
    border: 3.5px dashed #dc2626;
    color: #dc2626;
    background: rgba(254, 242, 242, 0.85);
}
@media (max-width: 900px) {
    .stamp-badge {
        position: static;
        transform: none;
        margin: 10px auto;
        display: inline-block;
    }
}
</style>

<div class="page-content" style="padding-top: 16px;">
    <!-- Document Control Toolbar (Hidden in Print) -->
    <div class="document-control-bar no-print" style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 14px 20px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <a href="<?= BASE_URL ?>/modules/invoices/" class="btn btn-outline btn-sm">
                ← Back to Invoices
            </a>
            <span style="color: var(--border-color);">|</span>
            <!-- Format Switcher -->
            <div class="btn-group" role="group" aria-label="Format Selector" style="display: inline-flex; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-color);">
                <button type="button" id="btnModeA4" class="btn btn-sm btn-primary" style="border-radius: 0;">
                    📄 Formal A4 Invoice
                </button>
                <button type="button" id="btnModeReceipt" class="btn btn-sm btn-outline" style="border-radius: 0; border-left: none;">
                    🧾 80mm Thermal Receipt
                </button>
            </div>
            <a href="#" id="orderDetailLink" class="btn btn-outline btn-sm" style="display: none;">
                📦 View Order
            </a>
        </div>

        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <?php if ($canManage): ?>
                <button type="button" id="btnOpenVoidModal" class="btn btn-outline btn-sm" style="color: var(--color-danger); border-color: var(--color-danger); display: none;">
                    ⚠️ Void Document
                </button>
            <?php endif; ?>

            <button type="button" id="btnPrintDocument" class="btn btn-primary btn-sm" style="font-weight: 600;">
                🖨️ Print Document
            </button>
        </div>
    </div>

    <!-- Loading State -->
    <div id="documentLoading" style="text-align: center; padding: 60px 20px; color: var(--text-muted);">
        <div style="display: inline-block; width: 32px; height: 32px; border: 3px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; margin-bottom: 12px;"></div>
        <div style="font-weight: 600; font-size: 15px;">Loading Document...</div>
    </div>

    <!-- Error State -->
    <div id="documentError" style="display: none; text-align: center; padding: 60px 20px; color: var(--color-danger);">
        <div style="font-size: 40px; margin-bottom: 12px;">⚠️</div>
        <div style="font-weight: 700; font-size: 18px; margin-bottom: 6px;" id="errorTitle">Document Not Found</div>
        <p id="errorMessage" style="color: var(--text-muted); font-size: 14px; max-width: 450px; margin: 0 auto 20px auto;"></p>
        <a href="<?= BASE_URL ?>/modules/invoices/" class="btn btn-outline">Return to Invoices</a>
    </div>

    <!-- Document Presentation Area -->
    <div id="documentContent" style="display: none;">
        <!-- Status Alert Banner -->
        <div id="statusBanner" class="status-banner no-print" style="margin-bottom: 20px; border-radius: var(--radius-md); padding: 14px 18px; display: none;">
            <!-- Injected via JS -->
        </div>

        <div class="document-preview-wrapper">
            <!-- ========================================================== -->
            <!-- Layout 1: Formal A4 Invoice Container                       -->
            <!-- ========================================================== -->
            <div id="invoiceA4Container" class="document-card invoice-a4">
                <!-- Watermark Stamp for PAID or VOID -->
                <div id="a4Stamp" class="stamp-badge"></div>

                <!-- Brand & Header -->
                <div class="doc-brand">
                    <div>
                        <div class="doc-brand-title" id="a4StoreName"><?= APP_NAME ?></div>
                        <div class="doc-brand-meta">
                            <span id="a4StoreTagline">Small Business Sales &amp; Inventory Hub</span><br>
                            <span id="a4StoreAddress">123 Commerce Avenue, Business District, Metro Manila</span><br>
                            Phone: <span id="a4StorePhone">+63 (02) 8123-4567</span> | Email: <span id="a4StoreEmail">billing@businesstracking.local</span>
                        </div>
                    </div>
                    <div class="doc-heading">
                        <div class="doc-title-badge" id="a4DocTitle">INVOICE</div>
                        <div id="a4StatusBadge" style="margin-top: 4px; display: inline-block;"></div>
                        <table class="doc-meta-table">
                            <tr>
                                <td class="label">Invoice No:</td>
                                <td class="value" id="a4InvoiceNo">—</td>
                            </tr>
                            <tr>
                                <td class="label">Receipt No:</td>
                                <td class="value" id="a4ReceiptNo">—</td>
                            </tr>
                            <tr>
                                <td class="label">Date:</td>
                                <td class="value" id="a4Date">—</td>
                            </tr>
                            <tr>
                                <td class="label">Order Ref:</td>
                                <td class="value" id="a4OrderRef">—</td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Parties Grid: Sold To & Shipping / COD Details -->
                <div class="parties-grid">
                    <div class="party-box">
                        <h4>Customer &amp; Delivery Destination</h4>
                        <p>
                            <strong id="a4CustomerName" style="font-size: 14.5px; color: #0f172a;">—</strong><br>
                            Phone: <span id="a4CustomerPhone">—</span><br>
                            <span id="a4CustomerEmail" style="display: none;">Email: <span id="a4CustomerEmailVal"></span><br></span>
                            Address: <span id="a4DeliveryAddress">—</span>
                        </p>
                    </div>

                    <div class="party-box">
                        <h4>Payment &amp; Terms</h4>
                        <p>
                            <strong>Payment Method:</strong> <span id="a4PaymentMethod">CASH ON DELIVERY (COD)</span><br>
                            <strong>Payment Status:</strong> <span id="a4PaymentStatus">—</span><br>
                            <strong>Amount Settled:</strong> <span id="a4AmountPaid" style="font-weight: 700;">—</span><br>
                            <span id="a4OrderNotesBox" style="display: none; margin-top: 6px; font-size: 12px; color: #64748b;">
                                <em>Notes: <span id="a4OrderNotes"></span></em>
                            </span>
                        </p>
                    </div>
                </div>

                <!-- Line Items Table -->
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="width: 140px;">SKU</th>
                            <th>Description</th>
                            <th style="width: 90px; text-align: center;">Qty</th>
                            <th style="width: 120px; text-align: right;">Unit Price</th>
                            <th style="width: 130px; text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody id="a4ItemsBody">
                        <!-- Populated by JS -->
                    </tbody>
                </table>

                <!-- Calculation Section: Zero Tax Notice & Financial Totals -->
                <div class="calculation-section">
                    <div class="tax-exemption-note">
                        <strong>🛡️ Zero Tax / Tax-Exempt Transaction</strong>
                        <p style="margin: 0; line-height: 1.4;">
                            In accordance with small business regulations, this transaction is strictly non-vatable / tax-exempt (0.00% Tax).<br>
                            <strong>Total Amount Due = Subtotal - Discount + Delivery Fee.</strong>
                        </p>
                    </div>

                    <table class="summary-table">
                        <tr>
                            <td class="label">Items Subtotal:</td>
                            <td class="value" id="a4Subtotal">—</td>
                        </tr>
                        <tr>
                            <td class="label">Discount:</td>
                            <td class="value" id="a4Discount" style="color: #dc2626;">—</td>
                        </tr>
                        <tr>
                            <td class="label">Delivery Fee:</td>
                            <td class="value" id="a4DeliveryFee">—</td>
                        </tr>
                        <tr>
                            <td class="label">Tax (0.00%):</td>
                            <td class="value" style="color: #16a34a; font-weight: 700;"><?= APP_CURRENCY ?>0.00</td>
                        </tr>
                        <tr class="grand-total">
                            <td class="label" style="font-weight: 800; color: #0f172a;">Total Due (COD):</td>
                            <td class="value" id="a4GrandTotal">—</td>
                        </tr>
                    </table>
                </div>

                <!-- Footer & Authorization -->
                <div class="doc-footer">
                    <div>
                        <p style="margin: 0 0 4px 0; font-weight: 600; color: #334155;">Thank you for your business!</p>
                        <p style="margin: 0; font-size: 11.5px; color: #94a3b8;">
                            Official receipt issued upon full remittance of COD payment. Please keep for return or exchange claims.
                        </p>
                    </div>
                    <div class="signature-box">
                        Authorized Signature
                    </div>
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- Layout 2: 80mm POS Thermal Receipt Container                -->
            <!-- ========================================================== -->
            <div id="receiptThermalContainer" class="document-card receipt-80mm" style="display: none;">
                <div class="r-center">
                    <div class="r-title" id="tStoreName"><?= APP_NAME ?></div>
                    <div style="font-size: 11.5px; color: #4b5563;" id="tStoreTagline">Sales &amp; Tracking Hub</div>
                    <div style="font-size: 11.5px; color: #4b5563;" id="tStoreAddress">Metro Manila, Philippines</div>
                    <div style="font-size: 11.5px; color: #4b5563;">Tel: <span id="tStorePhone">+63 2 8123-4567</span></div>
                </div>

                <div class="r-double-divider"></div>

                <div class="r-center r-bold" style="font-size: 13.5px; margin-bottom: 6px;">
                    OFFICIAL POS RECEIPT
                </div>

                <div class="r-meta-row">
                    <span>Receipt No:</span>
                    <strong id="tReceiptNo">—</strong>
                </div>
                <div class="r-meta-row">
                    <span>Invoice Ref:</span>
                    <span id="tInvoiceNo">—</span>
                </div>
                <div class="r-meta-row">
                    <span>Order No:</span>
                    <span id="tOrderNo">—</span>
                </div>
                <div class="r-meta-row">
                    <span>Date:</span>
                    <span id="tDate">—</span>
                </div>
                <div class="r-meta-row">
                    <span>Cashier/Staff:</span>
                    <span id="tCashier">Store POS</span>
                </div>

                <div class="r-divider"></div>

                <div style="font-size: 12px; margin-bottom: 6px;">
                    <div><strong>Customer:</strong> <span id="tCustomerName">—</span></div>
                    <div><strong>Phone:</strong> <span id="tCustomerPhone">—</span></div>
                    <div><strong>Address:</strong> <span id="tDeliveryAddress">—</span></div>
                </div>

                <div class="r-divider"></div>

                <!-- Items -->
                <table class="r-items-table">
                    <thead>
                        <tr>
                            <th style="width: 55%;">Item</th>
                            <th style="width: 15%; text-align: center;">Qty</th>
                            <th style="width: 30%; text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody id="tItemsBody">
                        <!-- Populated by JS -->
                    </tbody>
                </table>

                <div class="r-divider"></div>

                <div class="r-total-row">
                    <span>Subtotal:</span>
                    <span id="tSubtotal">—</span>
                </div>
                <div class="r-total-row" id="tDiscountRow" style="display: none;">
                    <span>Discount:</span>
                    <span id="tDiscount" style="color: #dc2626;">—</span>
                </div>
                <div class="r-total-row">
                    <span>Delivery Fee:</span>
                    <span id="tDeliveryFee">—</span>
                </div>
                <div class="r-total-row">
                    <span>Tax (Zero-Rated):</span>
                    <span><?= APP_CURRENCY ?>0.00</span>
                </div>

                <div class="r-divider"></div>

                <div class="r-grand-total">
                    <span>TOTAL DUE (COD):</span>
                    <span id="tGrandTotal">—</span>
                </div>

                <div class="r-double-divider"></div>

                <div class="r-meta-row">
                    <span>Payment Method:</span>
                    <strong>CASH ON DELIVERY</strong>
                </div>
                <div class="r-meta-row">
                    <span>Collection Status:</span>
                    <strong id="tCollectionStatus">—</strong>
                </div>

                <div class="r-barcode" id="tBarcode">
                    *INV-0000-0000*
                </div>

                <div class="r-center" style="font-size: 11px; color: #4b5563; margin-top: 10px;">
                    Thank you for your purchase!<br>
                    Goods sold in good condition.<br>
                    Zero-Rated Small Business Sales
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- Void Invoice Modal (Admin Only)                                -->
<!-- ============================================================== -->
<?php if ($canManage): ?>
<div class="modal-overlay" id="voidModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1050; align-items: center; justify-content: center; backdrop-filter: blur(2px);">
    <div class="modal-card" style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); width: 100%; max-width: 480px; box-shadow: var(--shadow-modal); overflow: hidden;">
        <div style="padding: 18px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <div style="font-weight: 700; font-size: 16px; color: var(--color-danger); display: flex; align-items: center; gap: 8px;">
                <span>⚠️</span> Void Invoice Document
            </div>
            <button type="button" id="closeVoidModalBtn" style="background: none; border: none; font-size: 20px; line-height: 1; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form id="voidInvoiceForm">
            <input type="hidden" name="action" value="void_invoice">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="invoice_id" id="voidInvoiceId" value="">

            <div style="padding: 20px 24px;">
                <div style="background: var(--color-danger-bg); border: 1px solid var(--color-danger-border); border-radius: var(--radius-md); padding: 12px; margin-bottom: 16px; font-size: 13px; color: #991b1b;">
                    <strong>Warning:</strong> Voiding this document marks it as invalid across all financial reports and audit trails. This action cannot be undone.
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label">Document Number</label>
                    <input type="text" id="voidDocNumber" class="form-input" readonly style="background: var(--bg-body); font-family: monospace; font-weight: 700;">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="voidReason">
                        Reason for Voiding <span style="color: var(--color-danger);">*</span>
                    </label>
                    <textarea id="voidReason" name="reason" class="form-textarea" rows="3" placeholder="Provide clear justification (e.g. Duplicate order, incorrect pricing, customer cancellation)..." required minlength="5"></textarea>
                    <small style="color: var(--text-muted); font-size: 12px;">Minimum 5 characters required for audit trail.</small>
                </div>
            </div>

            <div style="padding: 14px 24px; background: var(--bg-body); border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" id="cancelVoidBtn" class="btn btn-outline">Cancel</button>
                <button type="submit" id="confirmVoidBtn" class="btn btn-danger">Confirm Void</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const invoiceId = urlParams.get('id');
    const orderId = urlParams.get('order_id');
    const requestedMode = urlParams.get('mode') === 'receipt' ? 'receipt' : '<?= $initialMode ?>';

    // UI View elements
    const documentLoading = document.getElementById('documentLoading');
    const documentError = document.getElementById('documentError');
    const documentContent = document.getElementById('documentContent');
    const errorTitle = document.getElementById('errorTitle');
    const errorMessage = document.getElementById('errorMessage');
    const statusBanner = document.getElementById('statusBanner');
    const orderDetailLink = document.getElementById('orderDetailLink');

    const btnModeA4 = document.getElementById('btnModeA4');
    const btnModeReceipt = document.getElementById('btnModeReceipt');
    const btnPrintDocument = document.getElementById('btnPrintDocument');
    const btnOpenVoidModal = document.getElementById('btnOpenVoidModal');

    const invoiceA4Container = document.getElementById('invoiceA4Container');
    const receiptThermalContainer = document.getElementById('receiptThermalContainer');
    const a4Stamp = document.getElementById('a4Stamp');

    let currentInvoiceData = null;
    let activeMode = requestedMode;

    function formatCurrency(val) {
        return '<?= APP_CURRENCY ?>' + parseFloat(val || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function switchMode(mode) {
        activeMode = mode;
        if (mode === 'a4') {
            btnModeA4.classList.remove('btn-outline');
            btnModeA4.classList.add('btn-primary');
            btnModeReceipt.classList.remove('btn-primary');
            btnModeReceipt.classList.add('btn-outline');

            invoiceA4Container.style.display = 'block';
            receiptThermalContainer.style.display = 'none';

            document.body.classList.remove('print-mode-receipt');
            document.body.classList.add('print-mode-a4');
        } else {
            btnModeReceipt.classList.remove('btn-outline');
            btnModeReceipt.classList.add('btn-primary');
            btnModeA4.classList.remove('btn-primary');
            btnModeA4.classList.add('btn-outline');

            invoiceA4Container.style.display = 'none';
            receiptThermalContainer.style.display = 'block';

            document.body.classList.remove('print-mode-a4');
            document.body.classList.add('print-mode-receipt');
        }
    }

    btnModeA4.addEventListener('click', () => switchMode('a4'));
    btnModeReceipt.addEventListener('click', () => switchMode('receipt'));

    btnPrintDocument.addEventListener('click', () => {
        if (activeMode === 'a4') {
            document.body.classList.remove('print-mode-receipt');
            document.body.classList.add('print-mode-a4');
        } else {
            document.body.classList.remove('print-mode-a4');
            document.body.classList.add('print-mode-receipt');
        }
        window.print();
    });

    async function loadDocument() {
        documentLoading.style.display = 'block';
        documentError.style.display = 'none';
        documentContent.style.display = 'none';

        const params = new URLSearchParams({ action: 'get_invoice' });
        if (invoiceId) params.append('id', invoiceId);
        if (orderId) params.append('order_id', orderId);

        try {
            const res = await API.get(`handler.php?${params.toString()}`);
            if (!res.success) {
                documentLoading.style.display = 'none';
                documentError.style.display = 'block';
                errorTitle.textContent = 'Document Unavailable';
                errorMessage.textContent = res.message || 'Unable to retrieve invoice information.';
                return;
            }

            currentInvoiceData = res.data;
            renderDocument(res.data);

            documentLoading.style.display = 'none';
            documentContent.style.display = 'block';
            switchMode(activeMode);

        } catch (err) {
            console.error('Failed to load document:', err);
            documentLoading.style.display = 'none';
            documentError.style.display = 'block';
            errorTitle.textContent = 'Network or Server Error';
            errorMessage.textContent = 'Failed to connect to the backend server. Please verify your connection.';
        }
    }

    function renderDocument(data) {
        const { invoice, order, cod, items, business } = data;

        // Order detail button
        if (orderDetailLink && order.order_number) {
            orderDetailLink.href = `<?= BASE_URL ?>/modules/orders/?search=${encodeURIComponent(order.order_number)}`;
            orderDetailLink.style.display = 'inline-flex';
        }

        // Render Status Banner & Watermark Stamp
        statusBanner.style.display = 'none';
        a4Stamp.style.display = 'none';

        if (invoice.status === 'VOID') {
            statusBanner.style.display = 'block';
            statusBanner.style.background = 'var(--color-danger-bg)';
            statusBanner.style.border = '1px solid var(--color-danger-border)';
            statusBanner.style.color = '#991b1b';
            statusBanner.innerHTML = `
                <div style="font-weight: 700; font-size: 14.5px;">⚠️ VOIDED DOCUMENT</div>
                <div style="font-size: 13px; margin-top: 2px;">
                    This document was marked as VOID. Reason: <em>"${escapeHtml(invoice.void_reason || 'Administrative void')}"</em>.
                    It holds no legal or financial claim.
                </div>
            `;
            a4Stamp.className = 'stamp-badge stamp-void';
            a4Stamp.textContent = 'VOID';
            a4Stamp.style.display = 'block';
        } else if (invoice.status === 'PAID') {
            statusBanner.style.display = 'block';
            statusBanner.style.background = 'var(--color-success-bg)';
            statusBanner.style.border = '1px solid var(--color-success-border)';
            statusBanner.style.color = '#166534';
            statusBanner.innerHTML = `
                <div style="font-weight: 700; font-size: 14.5px;">✅ PAID IN FULL</div>
                <div style="font-size: 13px; margin-top: 2px;">
                    Payment of ${formatCurrency(invoice.amount_paid)} collected via Cash on Delivery.
                </div>
            `;
            a4Stamp.className = 'stamp-badge stamp-paid';
            a4Stamp.textContent = 'PAID (COD)';
            a4Stamp.style.display = 'block';
        }

        // Show/Hide Void button
        if (btnOpenVoidModal) {
            btnOpenVoidModal.style.display = (invoice.status !== 'VOID') ? 'inline-flex' : 'none';
        }

        // -------------------------------------------------------------
        // Populate A4 Layout
        // -------------------------------------------------------------
        document.getElementById('a4InvoiceNo').textContent = invoice.invoice_number;
        document.getElementById('a4ReceiptNo').textContent = invoice.receipt_number;
        document.getElementById('a4Date').textContent = invoice.issued_date;
        document.getElementById('a4OrderRef').textContent = order.order_number;

        let badgeHtml = '';
        if (invoice.status === 'PAID') {
            badgeHtml = '<span class="badge badge-delivered" style="font-size: 12px; padding: 4px 10px;">PAID</span>';
        } else if (invoice.status === 'ISSUED') {
            badgeHtml = '<span class="badge badge-pending" style="font-size: 12px; padding: 4px 10px;">ISSUED (PENDING COD)</span>';
        } else if (invoice.status === 'VOID') {
            badgeHtml = '<span class="badge badge-cancelled" style="font-size: 12px; padding: 4px 10px;">VOID</span>';
        }
        document.getElementById('a4StatusBadge').innerHTML = badgeHtml;

        document.getElementById('a4CustomerName').textContent = order.customer_name;
        document.getElementById('a4CustomerPhone').textContent = order.customer_phone;
        if (order.customer_email) {
            document.getElementById('a4CustomerEmail').style.display = 'inline';
            document.getElementById('a4CustomerEmailVal').textContent = order.customer_email;
        } else {
            document.getElementById('a4CustomerEmail').style.display = 'none';
        }
        document.getElementById('a4DeliveryAddress').textContent = order.delivery_address;

        document.getElementById('a4PaymentMethod').textContent = order.payment_method + ' (Cash on Delivery)';
        document.getElementById('a4PaymentStatus').textContent = invoice.status === 'PAID' ? 'Collected / Paid' : (invoice.status === 'VOID' ? 'Voided' : 'Pending Delivery Collection');
        document.getElementById('a4AmountPaid').textContent = formatCurrency(invoice.amount_paid);

        if (order.notes) {
            document.getElementById('a4OrderNotesBox').style.display = 'block';
            document.getElementById('a4OrderNotes').textContent = order.notes;
        } else {
            document.getElementById('a4OrderNotesBox').style.display = 'none';
        }

        // Items Table A4
        const a4ItemsBody = document.getElementById('a4ItemsBody');
        let a4Rows = '';
        items.forEach((itm, idx) => {
            a4Rows += `
                <tr>
                    <td style="color: #64748b; font-size: 12px;">${idx + 1}</td>
                    <td style="font-family: monospace; font-size: 12.5px; color: #475569;">${escapeHtml(itm.sku || '—')}</td>
                    <td style="font-weight: 600; color: #0f172a;">${escapeHtml(itm.product_name)}</td>
                    <td style="text-align: center; font-weight: 600;">${itm.quantity}</td>
                    <td style="text-align: right;" class="tabular-nums">${formatCurrency(itm.unit_price)}</td>
                    <td style="text-align: right; font-weight: 700;" class="tabular-nums">${formatCurrency(itm.subtotal)}</td>
                </tr>
            `;
        });
        a4ItemsBody.innerHTML = a4Rows;

        // A4 Summary
        document.getElementById('a4Subtotal').textContent = formatCurrency(order.subtotal);
        document.getElementById('a4Discount').textContent = order.discount_amount > 0 ? '-' + formatCurrency(order.discount_amount) : '₱0.00';
        document.getElementById('a4DeliveryFee').textContent = formatCurrency(order.delivery_fee);
        document.getElementById('a4GrandTotal').textContent = formatCurrency(order.total_amount);

        // -------------------------------------------------------------
        // Populate 80mm Thermal Receipt Layout
        // -------------------------------------------------------------
        document.getElementById('tReceiptNo').textContent = invoice.receipt_number;
        document.getElementById('tInvoiceNo').textContent = invoice.invoice_number;
        document.getElementById('tOrderNo').textContent = order.order_number;
        document.getElementById('tDate').textContent = invoice.issued_date;
        document.getElementById('tCustomerName').textContent = order.customer_name;
        document.getElementById('tCustomerPhone').textContent = order.customer_phone;
        document.getElementById('tDeliveryAddress').textContent = order.delivery_address;
        document.getElementById('tCollectionStatus').textContent = invoice.status;
        document.getElementById('tBarcode').textContent = `*${invoice.invoice_number}*`;

        const tItemsBody = document.getElementById('tItemsBody');
        let tRows = '';
        items.forEach(itm => {
            tRows += `
                <tr>
                    <td>
                        <div style="font-weight: bold;">${escapeHtml(itm.product_name)}</div>
                        <div style="font-size: 11px; color: #4b5563;">@ ${formatCurrency(itm.unit_price)}</div>
                    </td>
                    <td style="text-align: center; vertical-align: top;">${itm.quantity}</td>
                    <td style="text-align: right; vertical-align: top; font-weight: bold;">${formatCurrency(itm.subtotal)}</td>
                </tr>
            `;
        });
        tItemsBody.innerHTML = tRows;

        document.getElementById('tSubtotal').textContent = formatCurrency(order.subtotal);
        if (order.discount_amount > 0) {
            document.getElementById('tDiscountRow').style.display = 'flex';
            document.getElementById('tDiscount').textContent = '-' + formatCurrency(order.discount_amount);
        } else {
            document.getElementById('tDiscountRow').style.display = 'none';
        }
        document.getElementById('tDeliveryFee').textContent = formatCurrency(order.delivery_fee);
        document.getElementById('tGrandTotal').textContent = formatCurrency(order.total_amount);
    }

    // -------------------------------------------------------------
    // Void Modal Handling (Admin Only)
    // -------------------------------------------------------------
    <?php if ($canManage): ?>
    const voidModal = document.getElementById('voidModal');
    const closeVoidModalBtn = document.getElementById('closeVoidModalBtn');
    const cancelVoidBtn = document.getElementById('cancelVoidBtn');
    const voidInvoiceForm = document.getElementById('voidInvoiceForm');
    const voidInvoiceId = document.getElementById('voidInvoiceId');
    const voidDocNumber = document.getElementById('voidDocNumber');
    const voidReason = document.getElementById('voidReason');
    const confirmVoidBtn = document.getElementById('confirmVoidBtn');

    if (btnOpenVoidModal) {
        btnOpenVoidModal.addEventListener('click', () => {
            if (!currentInvoiceData) return;
            voidInvoiceId.value = currentInvoiceData.invoice.id;
            voidDocNumber.value = `${currentInvoiceData.invoice.invoice_number} (${currentInvoiceData.invoice.receipt_number})`;
            voidReason.value = '';
            voidModal.style.display = 'flex';
            voidReason.focus();
        });
    }

    function closeVoidModal() {
        voidModal.style.display = 'none';
    }

    if (closeVoidModalBtn) closeVoidModalBtn.addEventListener('click', closeVoidModal);
    if (cancelVoidBtn) cancelVoidBtn.addEventListener('click', closeVoidModal);

    if (voidInvoiceForm) {
        voidInvoiceForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const reason = voidReason.value.trim();
            if (reason.length < 5) {
                alert('Please enter a justification reason with at least 5 characters.');
                return;
            }

            confirmVoidBtn.disabled = true;
            confirmVoidBtn.textContent = 'Voiding...';

            try {
                const formData = new FormData(voidInvoiceForm);
                const res = await API.post('handler.php', formData);
                if (res.success) {
                    closeVoidModal();
                    Toast.success(res.message || 'Invoice voided successfully.');
                    loadDocument();
                } else {
                    alert(res.message || 'Failed to void invoice.');
                }
            } catch (err) {
                console.error('Void error:', err);
                alert('Network or server error during invoice voiding.');
            } finally {
                confirmVoidBtn.disabled = false;
                confirmVoidBtn.textContent = 'Confirm Void';
            }
        });
    }
    <?php endif; ?>

    // Initial trigger
    loadDocument();
});
</script>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
