<?php
/**
 * Invoice & Receipt Printable Document View
 * Small Business Sales and Expense Tracking System
 * Reference: View 10 (view-invoice-doc) in new-design.html
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
    right: 240px;
    padding: 8px 18px;
    text-transform: uppercase;
    font-weight: 800;
    font-size: 18px;
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
    background: rgba(240, 253, 244, 0.88);
}
.stamp-void {
    border: 3.5px dashed #dc2626;
    color: #dc2626;
    background: rgba(254, 242, 242, 0.88);
}

.invoice-a4-sheet {
    width: 100%;
    max-width: 860px;
    margin: 0 auto;
    background: #ffffff;
    padding: 44px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    position: relative;
}

.thermal-receipt {
    background: #fffef9;
    width: 350px;
    max-width: 100%;
    margin: 0 auto;
    padding: 24px 20px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    box-shadow: var(--shadow-md);
    font-family: 'Courier New', Courier, monospace;
    font-size: 12.5px;
    color: #1e293b;
    position: relative;
}

@media (max-width: 900px) {
    .invoice-a4-sheet {
        padding: 24px 20px;
    }
    .stamp-badge {
        position: static;
        transform: none;
        margin: 10px auto;
        display: inline-block;
    }
}
</style>

<div class="page-content" style="max-width: 920px; margin: 0 auto; padding-top: 16px;">
    <!-- Document Control Toolbar (Hidden in Print) -->
    <div class="document-control-bar no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 14px 20px; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <a href="<?= BASE_URL ?>/modules/invoices/" class="btn btn-outline btn-sm">
                &larr; Back to Invoices Ledger
            </a>
            <span style="color: var(--border-color);">|</span>
            <!-- Format Switcher -->
            <div style="display: inline-flex; border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; background: var(--bg-surface);">
                <button type="button" id="tabA4Btn" class="btn btn-sm btn-primary" style="border-radius: 0; border: none;">
                    📄 A4 Customer Invoice
                </button>
                <button type="button" id="tabThermalBtn" class="btn btn-sm btn-outline" style="border-radius: 0; border: none;">
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
            <!-- 1. Formal A4 Invoice Container                             -->
            <!-- ========================================================== -->
            <div id="docContainerA4" class="invoice-a4 invoice-a4-sheet">
                <!-- Watermark Stamp for PAID or VOID -->
                <div id="a4Stamp" class="stamp-badge"></div>

                <!-- Invoice Header -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 24px; border-bottom: 2px solid var(--color-primary); margin-bottom: 24px;">
                    <div>
                        <div style="font-size: 24px; font-weight: 800; color: var(--color-primary); letter-spacing: -0.02em;" id="a4StoreName">
                            <?= APP_NAME ?>
                        </div>
                        <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px; line-height: 1.5;">
                            <span id="a4StoreTagline">Prestige Apple Panel &bull; Official Operations &amp; Billing</span><br>
                            <span id="a4StoreAddress">123 Commercial Avenue, Business District, Metro Manila</span><br>
                            Phone: <span id="a4StorePhone">+63 (02) 8123-4567</span> &bull; VAT Reg. TIN: <span id="a4StoreTin">123-456-789-000</span>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 26px; font-weight: 800; color: var(--text-main); text-transform: uppercase;" id="a4DocTitle">
                            SALES INVOICE
                        </div>
                        <div style="font-family: monospace; font-size: 16px; font-weight: 700; color: var(--color-primary); margin-top: 4px;" id="a4InvoiceNo">
                            INV-2026-0000
                        </div>
                        <div style="margin-top: 8px;" id="a4StatusBadge">
                            <span class="badge badge-warning">ISSUED (PENDING COD)</span>
                        </div>
                    </div>
                </div>

                <!-- Bill To & Order Info Grid -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 30px;">
                    <div>
                        <div style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px;">
                            Billed / Shipped To:
                        </div>
                        <div style="font-size: 15px; font-weight: 700; color: var(--text-main);" id="a4CustomerName">
                            —
                        </div>
                        <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px; line-height: 1.5;">
                            Mobile: <span id="a4CustomerPhone">—</span><br>
                            <span id="a4CustomerEmailBox" style="display: none;">Email: <span id="a4CustomerEmail"></span><br></span>
                            Address: <span id="a4DeliveryAddress">—</span>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 13px; margin-bottom: 6px;">
                            <span style="color: var(--text-muted);">Issue Date:</span>
                            <strong style="color: var(--text-main); margin-left: 8px;" id="a4IssueDate">—</strong>
                        </div>
                        <div style="font-size: 13px; margin-bottom: 6px;">
                            <span style="color: var(--text-muted);">Order Reference:</span>
                            <strong style="font-family: monospace; color: var(--color-primary); margin-left: 8px;" id="a4OrderRef">ORD-2026-0000</strong>
                        </div>
                        <div style="font-size: 13px; margin-bottom: 6px;">
                            <span style="color: var(--text-muted);">Receipt No:</span>
                            <strong style="font-family: monospace; color: var(--text-muted); margin-left: 8px;" id="a4ReceiptNo">—</strong>
                        </div>
                        <div style="font-size: 13px;">
                            <span style="color: var(--text-muted);">Payment Method:</span>
                            <strong style="color: var(--text-main); margin-left: 8px;" id="a4PaymentMethod">Cash on Delivery (COD)</strong>
                        </div>
                    </div>
                </div>

                <!-- Line Items Table -->
                <div class="table-responsive" style="margin-bottom: 24px; border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                    <table class="table" style="margin-bottom: 0;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="width: 50px;">#</th>
                                <th style="width: 120px;">SKU</th>
                                <th>Description</th>
                                <th class="text-right" style="width: 80px;">Qty</th>
                                <th class="text-right" style="width: 120px;">Unit Price</th>
                                <th class="text-right" style="width: 130px;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="a4ItemsBody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Totals & Calculations Block -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 260px; max-width: 440px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 14px 18px; font-size: 12px; color: var(--text-muted); line-height: 1.5;">
                        <strong style="color: var(--text-main); display: block; margin-bottom: 4px;">🛡️ Non-VAT / Tax-Exempt</strong>
                        This transaction is strictly non-vatable / tax-exempt (0.00% Tax).<br>
                        <strong>Total Amount Due = Subtotal - Discount + Delivery Fee.</strong>
                    </div>

                    <div style="width: 320px;">
                        <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px;">
                            <span style="color: var(--text-muted);">Item Subtotal</span>
                            <span class="tabular-nums" style="font-weight: 600;" id="a4Subtotal">—</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px;" id="a4DiscountRow">
                            <span style="color: var(--text-muted);">Discount</span>
                            <span class="tabular-nums" style="color: var(--color-danger);" id="a4Discount">&minus; <?= APP_CURRENCY ?>0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 12px;">
                            <span style="color: var(--text-muted);">Delivery Fee</span>
                            <span class="tabular-nums" style="font-weight: 600;" id="a4DeliveryFee">—</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 17px; font-weight: 800; padding-top: 12px; border-top: 2px solid var(--border-color); color: var(--color-primary);">
                            <span>Total Amount Due</span>
                            <span class="tabular-nums" id="a4GrandTotal">—</span>
                        </div>
                    </div>
                </div>

                <!-- Footer & Authorization -->
                <div style="margin-top: 40px; padding-top: 20px; border-top: 1px dashed var(--border-color); display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; flex-wrap: wrap;">
                    <div style="font-size: 12px; color: var(--text-muted); max-width: 480px;">
                        <div style="font-weight: 600; color: var(--text-main); margin-bottom: 2px;">This serves as an official sales invoice.</div>
                        Official receipt issued upon full collection of payment. Thank you for your business!
                    </div>
                    <div style="text-align: center; min-width: 200px;">
                        <div id="a4AuthorizedSignatureName" style="font-weight: 700; font-size: 14px; color: var(--text-main); margin-bottom: 4px; min-height: 22px; letter-spacing: 0.02em;">—</div>
                        <div style="border-bottom: 1.5px solid var(--text-main); width: 200px; margin-bottom: 6px;"></div>
                        <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 0.05em;">
                            Authorized Signature
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- 2. Authentic 80mm Scalloped Thermal Receipt Container       -->
            <!-- ========================================================== -->
            <div id="docContainerThermal" class="receipt-80mm thermal-receipt" style="display: none;">
                <!-- Scalloped top edge indicator -->
                <div style="text-align: center; border-bottom: 1px dashed #94a3b8; padding-bottom: 12px; margin-bottom: 12px;">
                    <div style="font-size: 16px; font-weight: 800; letter-spacing: 0.04em;" id="tStoreName"><?= APP_NAME ?></div>
                    <div style="font-size: 11px; margin-top: 4px; color: #64748b;" id="tStoreAddress">123 Commercial Ave, Pasig City</div>
                    <div style="font-size: 11px; color: #64748b;">TIN: <span id="tStoreTin">123-456-789-000</span></div>
                    <div style="font-weight: 700; margin-top: 8px;">OFFICIAL RECEIPT</div>
                    <div style="font-size: 13px; font-weight: 700; color: #0f172a;" id="tReceiptNo">RCP-2026-0000</div>
                </div>

                <!-- Meta info -->
                <div style="font-size: 11px; margin-bottom: 12px; line-height: 1.5; color: #475569;">
                    <div>Date: <span id="tDate">—</span></div>
                    <div>Order Ref: <span id="tOrderNo" style="font-weight: 600;">—</span></div>
                    <div>Invoice Ref: <span id="tInvoiceNo">—</span></div>
                    <div>Customer: <span id="tCustomerName" style="font-weight: 600;">—</span></div>
                    <div>Cashier: <span id="tCashier">Store POS</span></div>
                </div>

                <!-- Items Table -->
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 11.5px;">
                    <thead>
                        <tr style="border-top: 1px dashed #94a3b8; border-bottom: 1px dashed #94a3b8;">
                            <th style="text-align: left; padding: 4px 0;">Item</th>
                            <th style="text-align: center; padding: 4px 0; width: 40px;">Qty</th>
                            <th style="text-align: right; padding: 4px 0; width: 75px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody id="tItemsBody">
                        <!-- Populated dynamically via JS -->
                    </tbody>
                </table>

                <!-- Calculation lines -->
                <div style="border-top: 1px dashed #94a3b8; padding-top: 8px; font-size: 12px; line-height: 1.6;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>Subtotal:</span>
                        <span id="tSubtotal">—</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;" id="tDiscountRow">
                        <span>Discount:</span>
                        <span id="tDiscount" style="color: #dc2626;">&minus; <?= APP_CURRENCY ?>0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Delivery Fee:</span>
                        <span id="tDeliveryFee">—</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 14px; border-top: 1px solid #1e293b; padding-top: 4px; margin-top: 4px;">
                        <span>TOTAL:</span>
                        <span id="tGrandTotal">—</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 4px;">
                        <span>Payment Method:</span>
                        <span>COD</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-weight: 700;">
                        <span>Status:</span>
                        <span id="tCollectionStatus">ISSUED</span>
                    </div>
                </div>

                <!-- Barcode simulation & footer -->
                <div style="text-align: center; margin-top: 18px; padding-top: 12px; border-top: 1px dashed #94a3b8;">
                    <div style="font-family: monospace; letter-spacing: 4px; font-size: 14px; margin-bottom: 4px;" id="tBarcode">|| | | ||| || ||| | |||</div>
                    <div style="font-size: 10px; color: #64748b;" id="tBarcodeLabel">* ORD-2026-0000 *</div>
                    <div style="font-size: 10px; color: #64748b; margin-top: 6px;">Thank you for shopping with us!</div>
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

    const tabA4Btn = document.getElementById('tabA4Btn');
    const tabThermalBtn = document.getElementById('tabThermalBtn');
    const btnPrintDocument = document.getElementById('btnPrintDocument');
    const btnOpenVoidModal = document.getElementById('btnOpenVoidModal');

    const docContainerA4 = document.getElementById('docContainerA4');
    const docContainerThermal = document.getElementById('docContainerThermal');
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

    function switchDocTab(type) {
        activeMode = type;
        if (type === 'thermal') {
            docContainerA4.style.display = 'none';
            docContainerThermal.style.display = 'block';
            tabA4Btn.className = 'btn btn-sm btn-outline';
            tabThermalBtn.className = 'btn btn-sm btn-primary';

            document.body.classList.remove('print-mode-a4');
            document.body.classList.add('print-mode-receipt');
        } else {
            docContainerA4.style.display = 'block';
            docContainerThermal.style.display = 'none';
            tabA4Btn.className = 'btn btn-sm btn-primary';
            tabThermalBtn.className = 'btn btn-sm btn-outline';

            document.body.classList.remove('print-mode-receipt');
            document.body.classList.add('print-mode-a4');
        }
    }

    tabA4Btn.addEventListener('click', () => switchDocTab('a4'));
    tabThermalBtn.addEventListener('click', () => switchDocTab('thermal'));

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
            switchDocTab(activeMode);

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
        document.getElementById('a4IssueDate').textContent = invoice.issued_date;
        document.getElementById('a4OrderRef').textContent = order.order_number;

        let badgeHtml = '';
        if (invoice.status === 'PAID') {
            badgeHtml = '<span class="badge badge-delivered">PAID</span>';
        } else if (invoice.status === 'ISSUED') {
            badgeHtml = '<span class="badge badge-pending">ISSUED (PENDING COD)</span>';
        } else if (invoice.status === 'VOID') {
            badgeHtml = '<span class="badge badge-cancelled">VOID</span>';
        }
        document.getElementById('a4StatusBadge').innerHTML = badgeHtml;

        document.getElementById('a4CustomerName').textContent = order.customer_name;
        document.getElementById('a4CustomerPhone').textContent = order.customer_phone || '—';
        if (order.customer_email) {
            document.getElementById('a4CustomerEmailBox').style.display = 'inline';
            document.getElementById('a4CustomerEmail').textContent = order.customer_email;
        } else {
            document.getElementById('a4CustomerEmailBox').style.display = 'none';
        }
        document.getElementById('a4DeliveryAddress').textContent = order.delivery_address || '—';
        document.getElementById('a4PaymentMethod').textContent = (order.payment_method || 'CASH') + ' (Cash on Delivery)';

        // Items Table A4
        const a4ItemsBody = document.getElementById('a4ItemsBody');
        let a4Rows = '';
        items.forEach((itm, idx) => {
            a4Rows += `
                <tr>
                    <td style="color: #64748b; font-size: 12px;">${idx + 1}</td>
                    <td style="font-family: monospace; font-weight: 600; color: var(--color-primary);">${escapeHtml(itm.sku || '—')}</td>
                    <td>
                        <div style="font-weight: 600; color: var(--text-main);">${escapeHtml(itm.product_name)}</div>
                        ${itm.category_name ? `<div style="font-size: 11px; color: var(--text-muted);">Category: ${escapeHtml(itm.category_name)}</div>` : ''}
                    </td>
                    <td class="text-right tabular-nums">${itm.quantity}</td>
                    <td class="text-right tabular-nums">${formatCurrency(itm.unit_price)}</td>
                    <td class="text-right tabular-nums" style="font-weight: 600;">${formatCurrency(itm.subtotal)}</td>
                </tr>
            `;
        });
        a4ItemsBody.innerHTML = a4Rows;

        // A4 Summary
        document.getElementById('a4Subtotal').textContent = formatCurrency(order.subtotal);
        if (order.discount_amount > 0) {
            document.getElementById('a4DiscountRow').style.display = 'flex';
            document.getElementById('a4Discount').textContent = '− ' + formatCurrency(order.discount_amount);
        } else {
            document.getElementById('a4DiscountRow').style.display = 'none';
        }
        document.getElementById('a4DeliveryFee').textContent = formatCurrency(order.delivery_fee);
        document.getElementById('a4GrandTotal').textContent = formatCurrency(order.total_amount);

        // Authorized Signature (Confirming user name only)
        const authorizedSignerName = invoice.authorized_signer || order.confirmed_by_name || 'Store Manager';
        const a4SigEl = document.getElementById('a4AuthorizedSignatureName');
        if (a4SigEl) {
            a4SigEl.textContent = authorizedSignerName;
        }

        // -------------------------------------------------------------
        // Populate 80mm Thermal Receipt Layout
        // -------------------------------------------------------------
        document.getElementById('tReceiptNo').textContent = invoice.receipt_number;
        document.getElementById('tInvoiceNo').textContent = invoice.invoice_number;
        document.getElementById('tOrderNo').textContent = order.order_number;
        document.getElementById('tDate').textContent = invoice.issued_date;
        document.getElementById('tCustomerName').textContent = order.customer_name;
        document.getElementById('tCashier').textContent = authorizedSignerName;
        document.getElementById('tCollectionStatus').textContent = invoice.status;
        document.getElementById('tBarcodeLabel').textContent = `* ${order.order_number} *`;

        const tItemsBody = document.getElementById('tItemsBody');
        let tRows = '';
        items.forEach(itm => {
            tRows += `
                <tr>
                    <td style="padding: 4px 0;">
                        <div style="font-weight: bold;">${escapeHtml(itm.product_name)}</div>
                        <div style="font-size: 11px; color: #475569;">@ ${formatCurrency(itm.unit_price)}</div>
                    </td>
                    <td style="text-align: center; vertical-align: top; padding: 4px 0;">${itm.quantity}</td>
                    <td style="text-align: right; vertical-align: top; padding: 4px 0; font-weight: bold;">${formatCurrency(itm.subtotal)}</td>
                </tr>
            `;
        });
        tItemsBody.innerHTML = tRows;

        document.getElementById('tSubtotal').textContent = formatCurrency(order.subtotal);
        if (order.discount_amount > 0) {
            document.getElementById('tDiscountRow').style.display = 'flex';
            document.getElementById('tDiscount').textContent = '− ' + formatCurrency(order.discount_amount);
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
