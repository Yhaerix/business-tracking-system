<?php
/**
 * Invoices & Receipts Management Ledger
 * Small Business Sales and Expense Tracking System
 * Reference: docs/UI_DESIGN_SPEC.md & View 9/10 in new-design.html
 */

declare(strict_types=1);

$pageTitle = 'Invoices & Receipts';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_permission('invoices_view');

$canManage = has_permission('invoices_manage');
$db = get_db();

// 1. Auto-generate invoice records for any orders that do not yet have an invoice
$uninvoicedOrders = $db->query("
    SELECT o.id, o.order_status, o.total_amount, o.created_at, cp.status as cod_status
    FROM orders o
    LEFT JOIN cod_payments cp ON cp.order_id = o.id
    WHERE o.deleted_at IS NULL AND o.id NOT IN (SELECT order_id FROM invoices)
    ORDER BY o.id ASC
")->fetchAll();

if (!empty($uninvoicedOrders)) {
    $year = date('Y');
    $stmtSeq = $db->prepare("
        SELECT invoice_number 
        FROM invoices 
        WHERE invoice_number LIKE :prefix 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmtSeq->execute([':prefix' => 'INV-' . $year . '-%']);
    $lastInv = $stmtSeq->fetch();
    $nextSeq = 1;
    if ($lastInv && !empty($lastInv['invoice_number']) && preg_match('/INV-\d{4}-(\d+)/', (string) $lastInv['invoice_number'], $m)) {
        $nextSeq = ((int) $m[1]) + 1;
    }

    $stmtInsertInv = $db->prepare("
        INSERT INTO invoices (
            order_id, invoice_number, receipt_number, issued_date, amount_paid, status, void_reason, created_at
        ) VALUES (
            :order_id, :inv_num, :rcp_num, :issued_date, :amount_paid, :status, :void_reason, :created_at
        )
    ");

    foreach ($uninvoicedOrders as $ord) {
        $invNum = sprintf('INV-%s-%04d', $year, $nextSeq);
        $rcpNum = sprintf('RCP-%s-%04d', $year, $nextSeq);
        $nextSeq++;

        $invStatus = 'ISSUED';
        $amountPaid = 0.00;
        $voidReason = null;
        if ($ord['order_status'] === 'CANCELLED') {
            $invStatus = 'VOID';
            $voidReason = 'Order was cancelled prior to invoicing';
        } elseif ($ord['order_status'] === 'DELIVERED' || in_array($ord['cod_status'] ?? '', ['COLLECTED', 'REMITTED'], true)) {
            $invStatus = 'PAID';
            $amountPaid = (float) $ord['total_amount'];
        }

        $issuedDate = !empty($ord['created_at']) ? substr((string) $ord['created_at'], 0, 10) : date('Y-m-d');
        $stmtInsertInv->execute([
            ':order_id'    => $ord['id'],
            ':inv_num'     => $invNum,
            ':rcp_num'     => $rcpNum,
            ':issued_date' => $issuedDate,
            ':amount_paid' => $amountPaid,
            ':status'      => $invStatus,
            ':void_reason' => $voidReason,
            ':created_at'  => $ord['created_at'] ?: date('Y-m-d H:i:s')
        ]);
    }
}

// 2. Auto-sync invoice statuses with orders/COD payments
$db->exec("
    UPDATE invoices i
    INNER JOIN orders o ON i.order_id = o.id
    LEFT JOIN cod_payments cp ON cp.order_id = o.id
    SET i.status = 'PAID', i.amount_paid = o.total_amount
    WHERE i.status = 'ISSUED' AND (o.order_status = 'DELIVERED' OR cp.status IN ('COLLECTED', 'REMITTED'))
");

$db->exec("
    UPDATE invoices i
    INNER JOIN orders o ON i.order_id = o.id
    SET i.status = 'VOID', i.void_reason = COALESCE(i.void_reason, 'Associated order was cancelled')
    WHERE i.status != 'VOID' AND o.order_status = 'CANCELLED'
");

// 3. Pre-load KPI summary metrics
$stmtSummary = $db->query("
    SELECT 
        COUNT(*) as total_invoices,
        COALESCE(SUM(CASE WHEN i.status != 'VOID' THEN o.total_amount ELSE 0 END), 0) as total_invoiced_amount,
        COALESCE(SUM(CASE WHEN i.status = 'PAID' THEN 1 ELSE 0 END), 0) as paid_count,
        COALESCE(SUM(CASE WHEN i.status = 'PAID' THEN i.amount_paid ELSE 0 END), 0) as paid_amount,
        COALESCE(SUM(CASE WHEN i.status = 'ISSUED' THEN 1 ELSE 0 END), 0) as pending_count,
        COALESCE(SUM(CASE WHEN i.status = 'ISSUED' THEN o.total_amount ELSE 0 END), 0) as pending_amount,
        COALESCE(SUM(CASE WHEN i.status = 'VOID' THEN 1 ELSE 0 END), 0) as void_count
    FROM invoices i
    INNER JOIN orders o ON i.order_id = o.id
");
$summary = $stmtSummary->fetch() ?: [];

$totalInvoices       = (int) ($summary['total_invoices'] ?? 0);
$totalInvoicedAmount = (float) ($summary['total_invoiced_amount'] ?? 0.00);
$paidCount           = (int) ($summary['paid_count'] ?? 0);
$paidAmount          = (float) ($summary['paid_amount'] ?? 0.00);
$pendingCount        = (int) ($summary['pending_count'] ?? 0);
$pendingAmount       = (float) ($summary['pending_amount'] ?? 0.00);
$voidCount           = (int) ($summary['void_count'] ?? 0);

include __DIR__ . '/../../includes/header.php';
?>

<!-- Ensure Print CSS is loaded for drawer printing -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">

<style>
/* Document Viewer & Drawer Specific Styling */
.stamp-badge {
    position: absolute;
    top: 40px;
    right: 30px;
    padding: 6px 16px;
    text-transform: uppercase;
    font-weight: 800;
    font-size: 16px;
    letter-spacing: 2px;
    border-radius: 6px;
    transform: rotate(-10deg);
    pointer-events: none;
    display: none;
    z-index: 10;
}
.stamp-paid {
    border: 3px dashed #16a34a;
    color: #16a34a;
    background: rgba(240, 253, 244, 0.88);
}
.stamp-void {
    border: 3px dashed #dc2626;
    color: #dc2626;
    background: rgba(254, 242, 242, 0.88);
}

.invoice-a4-sheet {
    width: 100%;
    background: #ffffff;
    padding: 32px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    position: relative;
    box-sizing: border-box;
}

.thermal-receipt {
    background: #fffef9;
    width: 340px;
    max-width: 100%;
    margin: 0 auto;
    padding: 24px 20px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    box-shadow: var(--shadow-sm);
    font-family: 'Courier New', Courier, monospace;
    font-size: 12.5px;
    color: #1e293b;
    position: relative;
    box-sizing: border-box;
}

/* Print Isolation for Drawer Printing */
@media print {
    body.drawer-printing-open .app-sidebar,
    body.drawer-printing-open .app-header,
    body.drawer-printing-open .page-header,
    body.drawer-printing-open .stats-grid,
    body.drawer-printing-open .card,
    body.drawer-printing-open .modal-header,
    body.drawer-printing-open .modal-footer,
    body.drawer-printing-open .no-print {
        display: none !important;
    }
    body.drawer-printing-open,
    body.drawer-printing-open .page-content,
    body.drawer-printing-open .app-main,
    body.drawer-printing-open .app-container {
        padding: 0 !important;
        margin: 0 !important;
        background: #ffffff !important;
    }
    body.drawer-printing-open #invoiceDrawerModal {
        position: static !important;
        display: block !important;
        background: transparent !important;
        padding: 0 !important;
        z-index: auto !important;
    }
    body.drawer-printing-open #invoiceDrawerModal .modal-card {
        box-shadow: none !important;
        border: none !important;
        max-width: 100% !important;
        width: 100% !important;
        height: auto !important;
        padding: 0 !important;
        overflow: visible !important;
        border-radius: 0 !important;
    }
    body.drawer-printing-open #invoiceDrawerModal .modal-body {
        padding: 0 !important;
        overflow: visible !important;
    }
}
</style>

<div class="page-content">
    <!-- Header Banner -->
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h1 class="page-title" style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0 0 4px 0;">Invoices &amp; Receipts</h1>
            <p style="margin: 0; color: var(--text-muted); font-size: 13.5px;">
                Sequential document repository, formal A4 customer invoices, and 80mm thermal POS receipts.
            </p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="<?= BASE_URL ?>/modules/orders/" class="btn btn-outline">
                📦 View Orders
            </a>
            <a href="<?= BASE_URL ?>/modules/orders/create.php" class="btn btn-primary">
                ➕ New Sale / Order
            </a>
        </div>
    </div>

    <!-- 4 Top KPI Stat Cards (Design System Standard) -->
    <div class="stats-grid">
        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Total Invoiced</span>
                <div class="stat-icon icon-primary">📄</div>
            </div>
            <div class="stat-value" id="kpiTotalAmount">
                <?= APP_CURRENCY ?><?= number_format($totalInvoicedAmount, 2) ?>
            </div>
            <div class="stat-subtext" id="kpiTotalCount">
                <?= number_format($totalInvoices) ?> total active documents
            </div>
        </div>

        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Paid / Collected</span>
                <div class="stat-icon icon-success">✅</div>
            </div>
            <div class="stat-value" style="color: var(--color-success);" id="kpiPaidAmount">
                <?= APP_CURRENCY ?><?= number_format($paidAmount, 2) ?>
            </div>
            <div class="stat-subtext" id="kpiPaidCount">
                <?= number_format($paidCount) ?> orders collected via COD
            </div>
        </div>

        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Pending Collection</span>
                <div class="stat-icon icon-warning">⏳</div>
            </div>
            <div class="stat-value" style="color: var(--color-warning);" id="kpiPendingAmount">
                <?= APP_CURRENCY ?><?= number_format($pendingAmount, 2) ?>
            </div>
            <div class="stat-subtext" id="kpiPendingCount">
                <?= number_format($pendingCount) ?> orders awaiting delivery
            </div>
        </div>

        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Void Documents</span>
                <div class="stat-icon icon-danger">🚫</div>
            </div>
            <div class="stat-value" style="color: var(--color-danger);" id="kpiVoidCount">
                <?= number_format($voidCount) ?>
            </div>
            <div class="stat-subtext">
                Cancelled / voided records
            </div>
        </div>
    </div>

    <!-- Filters & Search Card -->
    <div class="card" style="padding: 18px 20px; margin-bottom: 24px;">
        <form id="filterForm" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
            <div style="flex: 2; min-width: 220px;">
                <label class="form-label" style="margin-bottom: 5px;">Search Document</label>
                <input type="text" id="searchInput" class="form-input" placeholder="Search by Invoice #, Receipt #, Order #, Customer...">
            </div>

            <div style="flex: 1; min-width: 170px;">
                <label class="form-label" style="margin-bottom: 5px;">Status</label>
                <select id="statusFilter" class="form-select">
                    <option value="ALL">All Statuses</option>
                    <option value="ISSUED">ISSUED (Pending COD)</option>
                    <option value="PAID">PAID (Delivered / Collected)</option>
                    <option value="VOID">VOID (Cancelled)</option>
                </select>
            </div>

            <div style="flex: 1; min-width: 140px;">
                <label class="form-label" style="margin-bottom: 5px;">Date From</label>
                <input type="date" id="dateFrom" class="form-input">
            </div>

            <div style="flex: 1; min-width: 140px;">
                <label class="form-label" style="margin-bottom: 5px;">Date To</label>
                <input type="date" id="dateTo" class="form-input">
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary" style="height: 38px;">🔍 Filter</button>
                <button type="button" id="resetBtn" class="btn btn-outline" style="height: 38px;">Reset</button>
            </div>
        </form>
    </div>

    <!-- Data Table Container -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div class="card-header" style="padding: 16px 20px; margin-bottom: 0; border-bottom: 1px solid var(--border-color);">
            <div class="card-title" style="font-size: 15px;">Invoices &amp; Receipts Ledger</div>
            <div id="tableRecordCount" style="font-size: 13px; color: var(--text-muted);">Showing 0 records</div>
        </div>

        <div class="table-responsive" style="border: none; border-radius: 0;">
            <table class="table" id="invoicesTable">
                <thead>
                    <tr>
                        <th style="width: 140px;">Invoice #</th>
                        <th style="width: 140px;">Receipt #</th>
                        <th style="width: 130px;">Order Ref</th>
                        <th>Customer</th>
                        <th style="width: 110px;">Issued Date</th>
                        <th style="width: 130px; text-align: right;">Total Amount</th>
                        <th style="width: 120px; text-align: center;">Status</th>
                        <th style="width: 220px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="invoicesTableBody">
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">
                            Loading invoices...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; border-top: 1px solid var(--border-color); background: var(--bg-surface);">
            <div style="font-size: 13px; color: var(--text-muted);" id="paginationInfo">Page 1 of 1</div>
            <div style="display: flex; gap: 8px;" id="paginationButtons">
                <button type="button" class="btn btn-outline btn-sm" id="prevPageBtn" disabled>&larr; Previous</button>
                <button type="button" class="btn btn-outline btn-sm" id="nextPageBtn" disabled>Next &rarr;</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- INVOICE & RECEIPT SLIDE-OUT DRAWER PANEL                        -->
<!-- ============================================================== -->
<div class="modal-overlay" id="invoiceDrawerModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1040; backdrop-filter: blur(3px);">
    <div class="modal-card" style="display: flex; flex-direction: column; height: 100vh; max-height: 100vh; width: 100%; max-width: 820px; background: var(--bg-surface); box-shadow: var(--shadow-modal); overflow: hidden;">
        
        <!-- Drawer Header with Tab Switcher & Actions -->
        <div class="modal-header no-print" style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: #fff;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="font-weight: 700; font-size: 16px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 20px;">📄</span>
                    <span id="drawerDocHeading">Document Preview</span>
                </div>
                <!-- Format Switcher inside Drawer -->
                <div style="display: inline-flex; border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; background: var(--bg-surface);">
                    <button type="button" id="tabDrawerA4Btn" class="btn btn-sm btn-primary" style="border-radius: 0; border: none; font-size: 12px; padding: 4px 10px;">
                        📄 A4 Invoice
                    </button>
                    <button type="button" id="tabDrawerThermalBtn" class="btn btn-sm btn-outline" style="border-radius: 0; border: none; font-size: 12px; padding: 4px 10px;">
                        🧾 80mm Thermal
                    </button>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 8px;">
                <a href="#" id="drawerFullPageLink" target="_blank" class="btn btn-outline btn-sm" title="Open standalone page in new tab" style="font-size: 12px; padding: 4px 10px;">
                    ↗ Full Page
                </a>
                <button type="button" id="drawerPrintBtn" class="btn btn-primary btn-sm" style="font-size: 12px; padding: 4px 12px;">
                    🖨️ Print
                </button>
                <button type="button" id="closeInvoiceDrawerBtn" style="background: none; border: none; font-size: 24px; line-height: 1; cursor: pointer; color: var(--text-muted); margin-left: 4px;">&times;</button>
            </div>
        </div>

        <!-- Drawer Body (Scrollable Document Container) -->
        <div class="modal-body" style="flex: 1; overflow-y: auto; padding: 24px; background: #f8fafc;">
            <!-- Loading Indicator -->
            <div id="drawerLoading" style="text-align: center; padding: 60px 20px; color: var(--text-muted);">
                <div style="display: inline-block; width: 32px; height: 32px; border: 3px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; margin-bottom: 12px;"></div>
                <div style="font-weight: 600; font-size: 15px;">Loading Document...</div>
            </div>

            <!-- Error Indicator -->
            <div id="drawerError" style="display: none; text-align: center; padding: 40px 20px; color: var(--color-danger);">
                <div style="font-size: 36px; margin-bottom: 10px;">⚠️</div>
                <div style="font-weight: 700; font-size: 16px; margin-bottom: 6px;" id="drawerErrorTitle">Failed to Load</div>
                <p id="drawerErrorMessage" style="color: var(--text-muted); font-size: 13px; max-width: 400px; margin: 0 auto;"></p>
            </div>

            <!-- Content Area -->
            <div id="drawerContent" style="display: none;">
                <!-- Status Banner -->
                <div id="drawerStatusBanner" class="no-print" style="margin-bottom: 20px; border-radius: var(--radius-md); padding: 12px 16px; font-size: 13px; display: none;"></div>

                <!-- 1. Formal A4 Sheet Layout -->
                <div id="drawerDocA4" class="invoice-a4 invoice-a4-sheet">
                    <div id="drawerA4Stamp" class="stamp-badge"></div>

                    <!-- Header -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 20px; border-bottom: 2px solid var(--color-primary); margin-bottom: 20px;">
                        <div>
                            <div style="font-size: 20px; font-weight: 800; color: var(--color-primary); letter-spacing: -0.02em;" id="dA4StoreName">
                                <?= APP_NAME ?>
                            </div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.5;">
                                123 Commercial Avenue, Business District, Metro Manila<br>
                                Phone: +63 (02) 8123-4567 &bull; VAT Reg. TIN: 123-456-789-000
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 22px; font-weight: 800; color: var(--text-main); text-transform: uppercase;">
                                SALES INVOICE
                            </div>
                            <div style="font-family: monospace; font-size: 15px; font-weight: 700; color: var(--color-primary); margin-top: 2px;" id="dA4InvoiceNo">
                                INV-2026-0000
                            </div>
                            <div style="margin-top: 6px;" id="dA4StatusBadge"></div>
                        </div>
                    </div>

                    <!-- Bill To & Order Info Grid -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                        <div>
                            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">
                                Billed / Shipped To:
                            </div>
                            <div style="font-size: 14.5px; font-weight: 700; color: var(--text-main);" id="dA4CustomerName">
                                —
                            </div>
                            <div style="font-size: 12.5px; color: var(--text-muted); margin-top: 4px; line-height: 1.5;">
                                Mobile: <span id="dA4CustomerPhone">—</span><br>
                                <span id="dA4CustomerEmailBox" style="display: none;">Email: <span id="dA4CustomerEmail"></span><br></span>
                                Address: <span id="dA4DeliveryAddress">—</span>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 12.5px; margin-bottom: 4px;">
                                <span style="color: var(--text-muted);">Issue Date:</span>
                                <strong style="color: var(--text-main); margin-left: 6px;" id="dA4IssueDate">—</strong>
                            </div>
                            <div style="font-size: 12.5px; margin-bottom: 4px;">
                                <span style="color: var(--text-muted);">Order Reference:</span>
                                <strong style="font-family: monospace; color: var(--color-primary); margin-left: 6px;" id="dA4OrderRef">ORD-2026-0000</strong>
                            </div>
                            <div style="font-size: 12.5px; margin-bottom: 4px;">
                                <span style="color: var(--text-muted);">Receipt No:</span>
                                <strong style="font-family: monospace; color: var(--text-muted); margin-left: 6px;" id="dA4ReceiptNo">—</strong>
                            </div>
                            <div style="font-size: 12.5px;">
                                <span style="color: var(--text-muted);">Payment Method:</span>
                                <strong style="color: var(--text-main); margin-left: 6px;">Cash on Delivery (COD)</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="table-responsive" style="margin-bottom: 20px; border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                        <table class="table" style="margin-bottom: 0;">
                            <thead>
                                <tr style="background: #f8fafc;">
                                    <th style="width: 45px;">#</th>
                                    <th style="width: 110px;">SKU</th>
                                    <th>Description</th>
                                    <th class="text-right" style="width: 70px;">Qty</th>
                                    <th class="text-right" style="width: 110px;">Unit Price</th>
                                    <th class="text-right" style="width: 120px;">Total</th>
                                </tr>
                            </thead>
                            <tbody id="dA4ItemsBody"></tbody>
                        </table>
                    </div>

                    <!-- Calculations Block -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 220px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 12px 14px; font-size: 11.5px; color: var(--text-muted); line-height: 1.4;">
                            <strong style="color: var(--text-main); display: block; margin-bottom: 2px;">🛡️ Non-VAT / Small Business Tax-Exempt</strong>
                            Non-vatable / tax-exempt transaction (0.00% Tax).<br>
                            <strong>Total Due = Subtotal - Discount + Delivery Fee.</strong>
                        </div>

                        <div style="width: 280px;">
                            <div style="display: flex; justify-content: space-between; font-size: 12.5px; margin-bottom: 6px;">
                                <span style="color: var(--text-muted);">Item Subtotal</span>
                                <span class="tabular-nums" style="font-weight: 600;" id="dA4Subtotal">—</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 12.5px; margin-bottom: 6px;" id="dA4DiscountRow">
                                <span style="color: var(--text-muted);">Discount</span>
                                <span class="tabular-nums" style="color: var(--color-danger);" id="dA4Discount">&minus; <?= APP_CURRENCY ?>0.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 12.5px; margin-bottom: 10px;">
                                <span style="color: var(--text-muted);">Delivery Fee</span>
                                <span class="tabular-nums" style="font-weight: 600;" id="dA4DeliveryFee">—</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 15.5px; font-weight: 800; padding-top: 10px; border-top: 2px solid var(--border-color); color: var(--color-primary);">
                                <span>Total Amount Due</span>
                                <span class="tabular-nums" id="dA4GrandTotal">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- Footer Note -->
                    <div style="margin-top: 30px; padding-top: 16px; border-top: 1px dashed var(--border-color); text-align: center; font-size: 11.5px; color: var(--text-muted);">
                        This serves as an official sales invoice. Thank you for your business!
                    </div>
                </div>

                <!-- 2. Authentic 80mm Thermal Receipt Layout -->
                <div id="drawerDocThermal" class="receipt-80mm thermal-receipt" style="display: none;">
                    <div style="text-align: center; border-bottom: 1px dashed #94a3b8; padding-bottom: 10px; margin-bottom: 10px;">
                        <div style="font-size: 15px; font-weight: 800; letter-spacing: 0.04em;"><?= APP_NAME ?></div>
                        <div style="font-size: 11px; margin-top: 3px; color: #64748b;">123 Commercial Ave, Pasig City</div>
                        <div style="font-size: 11px; color: #64748b;">TIN: 123-456-789-000</div>
                        <div style="font-weight: 700; margin-top: 6px;">OFFICIAL RECEIPT</div>
                        <div style="font-size: 12.5px; font-weight: 700; color: #0f172a;" id="dTRcpNo">RCP-2026-0000</div>
                    </div>

                    <div style="font-size: 11px; margin-bottom: 10px; line-height: 1.5; color: #475569;">
                        <div>Date: <span id="dTDate">—</span></div>
                        <div>Order Ref: <span id="dTOrderNo" style="font-weight: 600;">—</span></div>
                        <div>Invoice Ref: <span id="dTInvNo">—</span></div>
                        <div>Customer: <span id="dTCustName" style="font-weight: 600;">—</span></div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 11.5px;">
                        <thead>
                            <tr style="border-top: 1px dashed #94a3b8; border-bottom: 1px dashed #94a3b8;">
                                <th style="text-align: left; padding: 4px 0;">Item</th>
                                <th style="text-align: center; padding: 4px 0; width: 35px;">Qty</th>
                                <th style="text-align: right; padding: 4px 0; width: 70px;">Amount</th>
                            </tr>
                        </thead>
                        <tbody id="dTItemsBody"></tbody>
                    </table>

                    <div style="border-top: 1px dashed #94a3b8; padding-top: 8px; font-size: 11.5px; line-height: 1.5;">
                        <div style="display: flex; justify-content: space-between;">
                            <span>Subtotal:</span>
                            <span id="dTSubtotal">—</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;" id="dTDiscountRow">
                            <span>Discount:</span>
                            <span id="dTDiscount" style="color: #dc2626;">&minus; <?= APP_CURRENCY ?>0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span>Delivery Fee:</span>
                            <span id="dTDeliveryFee">—</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 13.5px; border-top: 1px solid #1e293b; padding-top: 4px; margin-top: 4px;">
                            <span>TOTAL:</span>
                            <span id="dTGrandTotal">—</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-weight: 700; margin-top: 3px;">
                            <span>Status:</span>
                            <span id="dTStatus">ISSUED</span>
                        </div>
                    </div>

                    <div style="text-align: center; margin-top: 14px; padding-top: 10px; border-top: 1px dashed #94a3b8;">
                        <div style="font-family: monospace; letter-spacing: 4px; font-size: 13px; margin-bottom: 2px;">|| | | ||| || ||| | |||</div>
                        <div style="font-size: 9.5px; color: #64748b;" id="dTBarcodeLabel">* ORD-2026-0000 *</div>
                        <div style="font-size: 9.5px; color: #64748b; margin-top: 4px;">Thank you for shopping with us!</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Drawer Footer -->
        <div class="modal-footer no-print" style="padding: 14px 24px; border-top: 1px solid var(--border-color); background: #f7f9fe; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <?php if ($canManage): ?>
                    <button type="button" id="btnDrawerVoid" class="btn btn-outline btn-sm" style="color: var(--color-danger); border-color: var(--color-danger); display: none;">
                        ⚠️ Void Document
                    </button>
                <?php endif; ?>
            </div>
            <button type="button" id="btnDrawerCloseFooter" class="btn btn-outline btn-sm">
                Close Drawer
            </button>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- Void Invoice Modal (Admin Only)                                -->
<!-- ============================================================== -->
<?php if ($canManage): ?>
<div class="modal-overlay" id="voidModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1060; align-items: center; justify-content: center; backdrop-filter: blur(2px);">
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
    let currentPage = 1;
    const perPage = 15;

    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    const filterForm = document.getElementById('filterForm');
    const resetBtn = document.getElementById('resetBtn');
    const tbody = document.getElementById('invoicesTableBody');
    const tableRecordCount = document.getElementById('tableRecordCount');
    const paginationInfo = document.getElementById('paginationInfo');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');

    // KPI Elements
    const kpiTotalAmount = document.getElementById('kpiTotalAmount');
    const kpiTotalCount = document.getElementById('kpiTotalCount');
    const kpiPaidAmount = document.getElementById('kpiPaidAmount');
    const kpiPaidCount = document.getElementById('kpiPaidCount');
    const kpiPendingAmount = document.getElementById('kpiPendingAmount');
    const kpiPendingCount = document.getElementById('kpiPendingCount');
    const kpiVoidCount = document.getElementById('kpiVoidCount');

    // Drawer Elements
    const invoiceDrawerModal = document.getElementById('invoiceDrawerModal');
    const closeInvoiceDrawerBtn = document.getElementById('closeInvoiceDrawerBtn');
    const btnDrawerCloseFooter = document.getElementById('btnDrawerCloseFooter');
    const tabDrawerA4Btn = document.getElementById('tabDrawerA4Btn');
    const tabDrawerThermalBtn = document.getElementById('tabDrawerThermalBtn');
    const drawerPrintBtn = document.getElementById('drawerPrintBtn');
    const drawerFullPageLink = document.getElementById('drawerFullPageLink');
    const btnDrawerVoid = document.getElementById('btnDrawerVoid');
    const drawerDocA4 = document.getElementById('drawerDocA4');
    const drawerDocThermal = document.getElementById('drawerDocThermal');
    const drawerLoading = document.getElementById('drawerLoading');
    const drawerError = document.getElementById('drawerError');
    const drawerErrorMessage = document.getElementById('drawerErrorMessage');
    const drawerContent = document.getElementById('drawerContent');
    const drawerStatusBanner = document.getElementById('drawerStatusBanner');
    const drawerA4Stamp = document.getElementById('drawerA4Stamp');

    let currentDrawerInvoice = null;
    let activeDrawerMode = 'a4';

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

    async function loadInvoices(page = 1) {
        currentPage = page;
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">
                    <div style="display: inline-block; width: 20px; height: 20px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
                    Loading invoices...
                </td>
            </tr>
        `;

        const params = new URLSearchParams({
            action: 'list_invoices',
            page: currentPage,
            per_page: perPage,
            status: statusFilter.value,
            search: searchInput.value.trim(),
            date_from: dateFrom.value,
            date_to: dateTo.value
        });

        try {
            const res = await API.get(`handler.php?${params.toString()}`);
            if (!res.success) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: var(--color-danger);">
                            ${escapeHtml(res.message || 'Failed to load invoices.')}
                        </td>
                    </tr>
                `;
                return;
            }

            const { items, pagination, summary } = res.data;

            // Update KPI cards
            if (summary) {
                if (kpiTotalAmount) kpiTotalAmount.textContent = formatCurrency(summary.total_invoiced_amount);
                if (kpiTotalCount) kpiTotalCount.textContent = `${summary.total_invoices} total active documents`;
                if (kpiPaidAmount) kpiPaidAmount.textContent = formatCurrency(summary.paid_amount);
                if (kpiPaidCount) kpiPaidCount.textContent = `${summary.paid_count} orders collected via COD`;
                if (kpiPendingAmount) kpiPendingAmount.textContent = formatCurrency(summary.pending_amount);
                if (kpiPendingCount) kpiPendingCount.textContent = `${summary.pending_count} orders awaiting delivery`;
                if (kpiVoidCount) kpiVoidCount.textContent = summary.void_count;
            }

            if (!items || items.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 48px 20px; color: var(--text-muted);">
                            <div style="font-size: 36px; margin-bottom: 10px;">📄</div>
                            <div style="font-weight: 600; font-size: 15px; color: var(--text-main);">No Invoices Found</div>
                            <p style="font-size: 13px; margin: 4px 0 16px 0;">No invoice documents match your selected search or filter criteria.</p>
                        </td>
                    </tr>
                `;
                tableRecordCount.textContent = 'Showing 0 records';
                paginationInfo.textContent = 'Page 1 of 1';
                prevPageBtn.disabled = true;
                nextPageBtn.disabled = true;
                return;
            }

            tableRecordCount.textContent = `Showing ${(pagination.page - 1) * pagination.per_page + 1} to ${Math.min(pagination.page * pagination.per_page, pagination.total_items)} of ${pagination.total_items} records`;
            paginationInfo.textContent = `Page ${pagination.page} of ${pagination.total_pages || 1}`;
            prevPageBtn.disabled = pagination.page <= 1;
            nextPageBtn.disabled = pagination.page >= pagination.total_pages;

            let html = '';
            items.forEach(inv => {
                let badgeClass = 'badge-pending';
                let statusLabel = inv.status;
                if (inv.status === 'PAID') {
                    badgeClass = 'badge-delivered';
                    statusLabel = 'PAID (COD)';
                } else if (inv.status === 'ISSUED') {
                    badgeClass = 'badge-pending';
                    statusLabel = 'ISSUED';
                } else if (inv.status === 'VOID') {
                    badgeClass = 'badge-cancelled';
                    statusLabel = 'VOID';
                }

                html += `
                    <tr>
                        <td>
                            <a href="javascript:void(0)" onclick="openInvoiceDrawer(${inv.id}, 'a4')" style="font-family: monospace; font-weight: 700; color: var(--color-primary); text-decoration: none;" title="View A4 Invoice in Drawer">
                                ${escapeHtml(inv.invoice_number)}
                            </a>
                        </td>
                        <td>
                            <a href="javascript:void(0)" onclick="openInvoiceDrawer(${inv.id}, 'thermal')" style="font-family: monospace; font-size: 12.5px; color: var(--text-muted); text-decoration: none; cursor: pointer;" title="View Thermal Receipt in Drawer">
                                ${escapeHtml(inv.receipt_number)}
                            </a>
                        </td>
                        <td>
                            <a href="<?= BASE_URL ?>/modules/orders/?search=${encodeURIComponent(inv.order_number)}" style="font-family: monospace; text-decoration: none; color: var(--text-main); font-weight: 500;">
                                ${escapeHtml(inv.order_number)}
                            </a>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-main); font-size: 13.5px;">${escapeHtml(inv.customer_name)}</div>
                            <div style="font-size: 12px; color: var(--text-muted);">${escapeHtml(inv.customer_phone || '')}</div>
                        </td>
                        <td style="font-size: 13px; color: var(--text-main);">
                            ${escapeHtml(inv.issued_date)}
                        </td>
                        <td style="text-align: right; font-weight: 700; color: var(--text-main); font-size: 14px;" class="tabular-nums">
                            ${formatCurrency(inv.total_amount)}
                        </td>
                        <td style="text-align: center;">
                            <span class="badge ${badgeClass}">${escapeHtml(statusLabel)}</span>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <button type="button" class="btn btn-outline btn-sm" onclick="openInvoiceDrawer(${inv.id}, 'a4')" title="Open A4 Invoice in Drawer">
                                📄 A4 Invoice
                            </button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="openInvoiceDrawer(${inv.id}, 'thermal')" title="Open 80mm Thermal Receipt in Drawer">
                                🧾 Thermal
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;

        } catch (err) {
            console.error('Invoice load error:', err);
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" style="text-align: center; padding: 36px; color: var(--color-danger);">
                        Error communicating with server. Please try again.
                    </td>
                </tr>
            `;
        }
    }

    // -------------------------------------------------------------
    // Slide-out Drawer Panel Workflow
    // -------------------------------------------------------------
    window.openInvoiceDrawer = async function(invoiceId, mode = 'a4') {
        activeDrawerMode = mode;
        invoiceDrawerModal.style.display = 'flex';
        invoiceDrawerModal.classList.add('active');
        drawerLoading.style.display = 'block';
        drawerError.style.display = 'none';
        drawerContent.style.display = 'none';

        if (drawerFullPageLink) {
            drawerFullPageLink.href = `view.php?id=${invoiceId}&mode=${mode}`;
        }

        try {
            const res = await API.get(`handler.php?action=get_invoice&id=${invoiceId}`);
            if (!res.success) {
                drawerLoading.style.display = 'none';
                drawerError.style.display = 'block';
                drawerErrorMessage.textContent = res.message || 'Unable to retrieve invoice record.';
                return;
            }

            currentDrawerInvoice = res.data;
            renderDrawerDocument(res.data);

            drawerLoading.style.display = 'none';
            drawerContent.style.display = 'block';
            switchDrawerTab(activeDrawerMode);

        } catch (err) {
            console.error('Drawer invoice fetch error:', err);
            drawerLoading.style.display = 'none';
            drawerError.style.display = 'block';
            drawerErrorMessage.textContent = 'Network or server communication error.';
        }
    };

    function closeInvoiceDrawer() {
        invoiceDrawerModal.style.display = 'none';
        invoiceDrawerModal.classList.remove('active');
        document.body.classList.remove('drawer-printing-open', 'print-mode-a4', 'print-mode-receipt');
    }

    function switchDrawerTab(mode) {
        activeDrawerMode = mode;
        if (mode === 'thermal') {
            drawerDocA4.style.display = 'none';
            drawerDocThermal.style.display = 'block';
            tabDrawerA4Btn.className = 'btn btn-sm btn-outline';
            tabDrawerThermalBtn.className = 'btn btn-sm btn-primary';
            if (drawerFullPageLink && currentDrawerInvoice) {
                drawerFullPageLink.href = `view.php?id=${currentDrawerInvoice.invoice.id}&mode=receipt`;
            }
        } else {
            drawerDocA4.style.display = 'block';
            drawerDocThermal.style.display = 'none';
            tabDrawerA4Btn.className = 'btn btn-sm btn-primary';
            tabDrawerThermalBtn.className = 'btn btn-sm btn-outline';
            if (drawerFullPageLink && currentDrawerInvoice) {
                drawerFullPageLink.href = `view.php?id=${currentDrawerInvoice.invoice.id}&mode=a4`;
            }
        }
    }

    function renderDrawerDocument(data) {
        const { invoice, order, items } = data;

        document.getElementById('drawerDocHeading').textContent = `${invoice.invoice_number} / ${invoice.receipt_number}`;

        // Status Banner & Watermark Stamp
        drawerStatusBanner.style.display = 'none';
        drawerA4Stamp.style.display = 'none';

        if (invoice.status === 'VOID') {
            drawerStatusBanner.style.display = 'block';
            drawerStatusBanner.style.background = 'var(--color-danger-bg)';
            drawerStatusBanner.style.border = '1px solid var(--color-danger-border)';
            drawerStatusBanner.style.color = '#991b1b';
            drawerStatusBanner.innerHTML = `
                <strong>⚠️ VOIDED DOCUMENT</strong>: Marked as VOID. Reason: <em>"${escapeHtml(invoice.void_reason || 'Administrative void')}"</em>.
            `;
            drawerA4Stamp.className = 'stamp-badge stamp-void';
            drawerA4Stamp.textContent = 'VOID';
            drawerA4Stamp.style.display = 'block';
        } else if (invoice.status === 'PAID') {
            drawerStatusBanner.style.display = 'block';
            drawerStatusBanner.style.background = 'var(--color-success-bg)';
            drawerStatusBanner.style.border = '1px solid var(--color-success-border)';
            drawerStatusBanner.style.color = '#166534';
            drawerStatusBanner.innerHTML = `
                <strong>✅ PAID IN FULL</strong>: Payment of ${formatCurrency(invoice.amount_paid)} collected via Cash on Delivery.
            `;
            drawerA4Stamp.className = 'stamp-badge stamp-paid';
            drawerA4Stamp.textContent = 'PAID (COD)';
            drawerA4Stamp.style.display = 'block';
        }

        if (btnDrawerVoid) {
            btnDrawerVoid.style.display = (invoice.status !== 'VOID') ? 'inline-flex' : 'none';
        }

        // 1. Populate A4 Sheet
        document.getElementById('dA4InvoiceNo').textContent = invoice.invoice_number;
        document.getElementById('dA4ReceiptNo').textContent = invoice.receipt_number;
        document.getElementById('dA4IssueDate').textContent = invoice.issued_date;
        document.getElementById('dA4OrderRef').textContent = order.order_number;

        let badgeHtml = '';
        if (invoice.status === 'PAID') {
            badgeHtml = '<span class="badge badge-delivered" style="font-size: 11px;">PAID</span>';
        } else if (invoice.status === 'ISSUED') {
            badgeHtml = '<span class="badge badge-pending" style="font-size: 11px;">ISSUED (PENDING COD)</span>';
        } else if (invoice.status === 'VOID') {
            badgeHtml = '<span class="badge badge-cancelled" style="font-size: 11px;">VOID</span>';
        }
        document.getElementById('dA4StatusBadge').innerHTML = badgeHtml;

        document.getElementById('dA4CustomerName').textContent = order.customer_name;
        document.getElementById('dA4CustomerPhone').textContent = order.customer_phone || '—';
        if (order.customer_email) {
            document.getElementById('dA4CustomerEmailBox').style.display = 'inline';
            document.getElementById('dA4CustomerEmail').textContent = order.customer_email;
        } else {
            document.getElementById('dA4CustomerEmailBox').style.display = 'none';
        }
        document.getElementById('dA4DeliveryAddress').textContent = order.delivery_address || '—';

        // Items A4
        const dA4ItemsBody = document.getElementById('dA4ItemsBody');
        let a4Rows = '';
        items.forEach((itm, idx) => {
            a4Rows += `
                <tr>
                    <td style="color: #64748b; font-size: 11.5px;">${idx + 1}</td>
                    <td style="font-family: monospace; font-weight: 600; color: var(--color-primary); font-size: 12px;">${escapeHtml(itm.sku || '—')}</td>
                    <td>
                        <div style="font-weight: 600; font-size: 13px; color: var(--text-main);">${escapeHtml(itm.product_name)}</div>
                        ${itm.category_name ? `<div style="font-size: 11px; color: var(--text-muted);">Category: ${escapeHtml(itm.category_name)}</div>` : ''}
                    </td>
                    <td class="text-right tabular-nums">${itm.quantity}</td>
                    <td class="text-right tabular-nums">${formatCurrency(itm.unit_price)}</td>
                    <td class="text-right tabular-nums" style="font-weight: 600;">${formatCurrency(itm.subtotal)}</td>
                </tr>
            `;
        });
        dA4ItemsBody.innerHTML = a4Rows;

        document.getElementById('dA4Subtotal').textContent = formatCurrency(order.subtotal);
        if (order.discount_amount > 0) {
            document.getElementById('dA4DiscountRow').style.display = 'flex';
            document.getElementById('dA4Discount').textContent = '− ' + formatCurrency(order.discount_amount);
        } else {
            document.getElementById('dA4DiscountRow').style.display = 'none';
        }
        document.getElementById('dA4DeliveryFee').textContent = formatCurrency(order.delivery_fee);
        document.getElementById('dA4GrandTotal').textContent = formatCurrency(order.total_amount);

        // 2. Populate 80mm Thermal
        document.getElementById('dTRcpNo').textContent = invoice.receipt_number;
        document.getElementById('dTInvNo').textContent = invoice.invoice_number;
        document.getElementById('dTOrderNo').textContent = order.order_number;
        document.getElementById('dTDate').textContent = invoice.issued_date;
        document.getElementById('dTCustName').textContent = order.customer_name;
        document.getElementById('dTStatus').textContent = invoice.status;
        document.getElementById('dTBarcodeLabel').textContent = `* ${order.order_number} *`;

        const dTItemsBody = document.getElementById('dTItemsBody');
        let tRows = '';
        items.forEach(itm => {
            tRows += `
                <tr>
                    <td style="padding: 3px 0;">
                        <div style="font-weight: bold;">${escapeHtml(itm.product_name)}</div>
                        <div style="font-size: 10px; color: #475569;">@ ${formatCurrency(itm.unit_price)}</div>
                    </td>
                    <td style="text-align: center; vertical-align: top; padding: 3px 0;">${itm.quantity}</td>
                    <td style="text-align: right; vertical-align: top; padding: 3px 0; font-weight: bold;">${formatCurrency(itm.subtotal)}</td>
                </tr>
            `;
        });
        dTItemsBody.innerHTML = tRows;

        document.getElementById('dTSubtotal').textContent = formatCurrency(order.subtotal);
        if (order.discount_amount > 0) {
            document.getElementById('dTDiscountRow').style.display = 'flex';
            document.getElementById('dTDiscount').textContent = '− ' + formatCurrency(order.discount_amount);
        } else {
            document.getElementById('dTDiscountRow').style.display = 'none';
        }
        document.getElementById('dTDeliveryFee').textContent = formatCurrency(order.delivery_fee);
        document.getElementById('dTGrandTotal').textContent = formatCurrency(order.total_amount);
    }

    tabDrawerA4Btn.addEventListener('click', () => switchDrawerTab('a4'));
    tabDrawerThermalBtn.addEventListener('click', () => switchDrawerTab('thermal'));
    closeInvoiceDrawerBtn.addEventListener('click', closeInvoiceDrawer);
    btnDrawerCloseFooter.addEventListener('click', closeInvoiceDrawer);

    // Close when clicking outside drawer (only if mousedown started on backdrop)
    let invDrawerMouseDownTarget = null;
    invoiceDrawerModal.addEventListener('mousedown', (e) => {
        invDrawerMouseDownTarget = e.target;
    });
    invoiceDrawerModal.addEventListener('click', (e) => {
        if (e.target === invoiceDrawerModal && invDrawerMouseDownTarget === invoiceDrawerModal) {
            closeInvoiceDrawer();
        }
        invDrawerMouseDownTarget = null;
    });

    // Drawer Print Button Trigger
    drawerPrintBtn.addEventListener('click', () => {
        document.body.classList.add('drawer-printing-open');
        if (activeDrawerMode === 'a4') {
            document.body.classList.remove('print-mode-receipt');
            document.body.classList.add('print-mode-a4');
        } else {
            document.body.classList.remove('print-mode-a4');
            document.body.classList.add('print-mode-receipt');
        }
        window.print();
        setTimeout(() => {
            document.body.classList.remove('drawer-printing-open');
        }, 1000);
    });

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

    if (btnDrawerVoid) {
        btnDrawerVoid.addEventListener('click', () => {
            if (!currentDrawerInvoice) return;
            voidInvoiceId.value = currentDrawerInvoice.invoice.id;
            voidDocNumber.value = `${currentDrawerInvoice.invoice.invoice_number} (${currentDrawerInvoice.invoice.receipt_number})`;
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
    voidModal.addEventListener('click', (e) => {
        if (e.target === voidModal) closeVoidModal();
    });

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
                    if (currentDrawerInvoice) {
                        openInvoiceDrawer(currentDrawerInvoice.invoice.id, activeDrawerMode);
                    }
                    loadInvoices(currentPage);
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

    // Form Event Handlers
    filterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        loadInvoices(1);
    });

    resetBtn.addEventListener('click', () => {
        searchInput.value = '';
        statusFilter.value = 'ALL';
        dateFrom.value = '';
        dateTo.value = '';
        loadInvoices(1);
    });

    prevPageBtn.addEventListener('click', () => {
        if (currentPage > 1) {
            loadInvoices(currentPage - 1);
        }
    });

    nextPageBtn.addEventListener('click', () => {
        loadInvoices(currentPage + 1);
    });

    // Initial load
    loadInvoices(1);
});
</script>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
