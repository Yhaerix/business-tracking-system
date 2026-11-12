<?php
/**
 * Invoices & Receipts Management Ledger
 * Small Business Sales and Expense Tracking System
 * Reference: docs/UI_DESIGN_SPEC.md
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
    WHERE o.id NOT IN (SELECT order_id FROM invoices)
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
                <button type="button" class="btn btn-outline btn-sm" id="prevPageBtn" disabled>Previous</button>
                <button type="button" class="btn btn-outline btn-sm" id="nextPageBtn" disabled>Next</button>
            </div>
        </div>
    </div>
</div>

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
                            <a href="view.php?id=${inv.id}" style="font-family: monospace; font-weight: 700; color: var(--color-primary); text-decoration: none;">
                                ${escapeHtml(inv.invoice_number)}
                            </a>
                        </td>
                        <td>
                            <span style="font-family: monospace; font-size: 12.5px; color: var(--text-muted);">
                                ${escapeHtml(inv.receipt_number)}
                            </span>
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
                            <a href="view.php?id=${inv.id}" class="btn btn-outline btn-sm" title="View Formal A4 Invoice">
                                📄 A4 Invoice
                            </a>
                            <a href="view.php?id=${inv.id}&mode=receipt" class="btn btn-outline btn-sm" title="View 80mm Thermal Receipt">
                                🧾 Thermal
                            </a>
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

    // Event Handlers
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
