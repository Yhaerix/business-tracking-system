<?php
/**
 * Orders Management Dashboard & Detail Drawer (COD Workflow)
 * Small Business Sales and Expense Tracking System
 *
 * File: modules/orders/index.php
 */

declare(strict_types=1);

$pageTitle = 'Orders & Delivery Management';

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_login();
require_permission('orders_view');

$canCreate       = has_permission('orders_create');
$canUpdateStatus = has_permission('orders_update_status');
$canCancel       = has_permission('orders_cancel');
$canRemit        = has_permission('orders_remit_cod'); // Manager & Admin only

$db = get_db();

// Pre-load top 4 KPI cards directly from DB for immediate server-side render
$kpiStmt = $db->query("
    SELECT 
        COUNT(CASE WHEN o.order_status IN ('PENDING', 'CONFIRMED') THEN 1 END) AS pending_count,
        COUNT(CASE WHEN o.order_status = 'DISPATCHED' THEN 1 END) AS dispatched_count,
        COUNT(CASE WHEN o.order_status = 'DELIVERED' THEN 1 END) AS delivered_count,
        COALESCE(SUM(CASE WHEN cp.status = 'COLLECTED' THEN cp.amount_collected ELSE 0 END), 0.00) AS unremitted_cod_cash
    FROM orders o
    LEFT JOIN cod_payments cp ON o.id = cp.order_id
    WHERE o.deleted_at IS NULL
");
$initialKpi = $kpiStmt->fetch() ?: [
    'pending_count' => 0,
    'dispatched_count' => 0,
    'delivered_count' => 0,
    'unremitted_cod_cash' => 0.00
];

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* Drawer Modal Customizations */
.order-drawer-card {
    max-width: 860px;
    width: 95%;
}

.order-detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .order-detail-grid {
        grid-template-columns: 1fr;
    }
}

.detail-info-block {
    background: #f8fafc;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 14px;
}

.detail-info-title {
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-muted);
    letter-spacing: 0.04em;
    margin-bottom: 8px;
}

.detail-info-row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    margin-bottom: 6px;
}

.detail-info-row:last-child {
    margin-bottom: 0;
}

.detail-info-label {
    color: var(--text-muted);
}

.detail-info-val {
    font-weight: 600;
    color: var(--text-main);
}

/* Timeline Notes */
.timeline-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 240px;
    overflow-y: auto;
    padding-right: 4px;
    margin-bottom: 12px;
}

.timeline-item {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-left: 3px solid var(--color-primary);
    border-radius: var(--radius-sm);
    padding: 10px 12px;
}

.timeline-meta {
    display: flex;
    justify-content: space-between;
    font-size: 11.5px;
    color: var(--text-muted);
    margin-bottom: 4px;
}

.timeline-text {
    font-size: 13px;
    color: var(--text-main);
    line-height: 1.4;
    word-break: break-word;
}
</style>

<!-- Action Header -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0;">Orders & Delivery Management</h1>
        <p style="font-size: 13.5px; color: var(--text-muted); margin: 4px 0 0 0;">
            Manage Cash-on-Delivery (COD) lifecycles, dispatch couriers, track collected cash, and verify store remittance.
        </p>
    </div>

    <div>
        <?php if ($canCreate): ?>
            <a href="create.php" class="btn btn-primary">
                <span>+</span> New Order (POS)
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- 4 Top KPI Stat Cards -->
<div class="stats-grid">
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Pending / Confirmed</span>
            <div class="stat-icon icon-warning">⏳</div>
        </div>
        <div class="stat-value" id="kpiPending"><?= number_format((int)$initialKpi['pending_count']) ?></div>
        <div class="stat-subtext">Awaiting courier dispatch</div>
    </div>

    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Dispatched / In Transit</span>
            <div class="stat-icon icon-primary">🚚</div>
        </div>
        <div class="stat-value" id="kpiDispatched"><?= number_format((int)$initialKpi['dispatched_count']) ?></div>
        <div class="stat-subtext">Out for COD delivery</div>
    </div>

    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Delivered (Collected)</span>
            <div class="stat-icon icon-success">✅</div>
        </div>
        <div class="stat-value" id="kpiDelivered"><?= number_format((int)$initialKpi['delivered_count']) ?></div>
        <div class="stat-subtext">Cash received by courier</div>
    </div>

    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Unremitted COD Cash</span>
            <div class="stat-icon icon-warning">💰</div>
        </div>
        <div class="stat-value" id="kpiUnremitted" style="color: #b45309;">
            <?= APP_CURRENCY . number_format((float)$initialKpi['unremitted_cod_cash'], 2) ?>
        </div>
        <div class="stat-subtext">Held by courier / pending store drawer</div>
    </div>
</div>

<!-- Filter & Search Bar -->
<div class="filter-bar">
    <div class="filter-group" style="min-width: 240px;">
        <input 
            type="text" 
            id="filterSearch" 
            class="form-input" 
            placeholder="Search Order #, customer name, phone..."
        >
    </div>

    <div class="filter-group" style="max-width: 170px;">
        <select id="filterOrderStatus" class="form-select">
            <option value="">All Order Statuses</option>
            <option value="CONFIRMED">CONFIRMED</option>
            <option value="DISPATCHED">DISPATCHED</option>
            <option value="DELIVERED">DELIVERED</option>
            <option value="CANCELLED">CANCELLED</option>
            <option value="RETURNED">RETURNED</option>
        </select>
    </div>

    <div class="filter-group" style="max-width: 180px;">
        <select id="filterPaymentStatus" class="form-select">
            <option value="">All Payment Statuses</option>
            <option value="PENDING_COLLECTION">Pending Collection</option>
            <option value="COLLECTED">Cash Collected</option>
            <option value="REMITTED">Remitted to Store</option>
            <option value="FAILED">Failed / Cancelled</option>
        </select>
    </div>

    <div class="filter-group" style="max-width: 150px;">
        <input type="date" id="filterDateFrom" class="form-input" title="Date From">
    </div>

    <div class="filter-group" style="max-width: 150px;">
        <input type="date" id="filterDateTo" class="form-input" title="Date To">
    </div>

    <div class="filter-group-fixed" style="display: flex; gap: 8px;">
        <button type="button" id="applyFiltersBtn" class="btn btn-primary btn-sm">
            Filter
        </button>
        <button type="button" id="resetFiltersBtn" class="btn btn-outline btn-sm">
            Reset
        </button>
    </div>
</div>

<!-- Orders Data Table -->
<div class="table-responsive">
    <table class="table" id="ordersTable">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Date & Staff</th>
                <th>Customer Details</th>
                <th>Line Items</th>
                <th class="text-right">Total Due</th>
                <th class="text-center">Order Status</th>
                <th class="text-center">COD Payment</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody id="ordersTableBody">
            <tr>
                <td colspan="8" class="text-center" style="padding: 40px; color: var(--text-muted);">
                    Loading order records...
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Pagination Controls -->
    <div class="pagination-container" id="paginationBar" style="display: none;">
        <div id="paginationInfo">Showing 0 of 0 orders</div>
        <div class="pagination-controls">
            <button type="button" class="btn btn-outline btn-sm" id="prevPageBtn">Previous</button>
            <span id="pageIndicator" style="font-weight: 600; padding: 0 4px;">Page 1 of 1</span>
            <button type="button" class="btn btn-outline btn-sm" id="nextPageBtn">Next</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Order Details & Workflow Drawer                                   -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="orderDetailModal">
    <div class="modal-card order-drawer-card">
        <div class="modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <h3 class="modal-title" id="drawerOrderNumber">Order Details</h3>
                <span id="drawerOrderStatusBadge" class="badge">STATUS</span>
                <span id="drawerPaymentStatusBadge" class="badge">COD</span>
            </div>
            <button type="button" class="modal-close" id="closeDetailModalBtn">&times;</button>
        </div>

        <div class="modal-body" id="drawerModalBody">
            <!-- Rendered dynamically -->
        </div>

        <div class="modal-footer" id="drawerModalFooter">
            <!-- Dynamic state machine buttons injected here -->
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Mark as Delivered (Courier & Collected Amount Prompt)              -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="deliverModal">
    <div class="modal-card" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title">Mark Order as Delivered</h3>
            <button type="button" class="modal-close" id="closeDeliverModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size: 13.5px; color: var(--text-muted); margin-bottom: 16px;">
                Confirm that the order has been handed to the recipient and Cash on Delivery has been collected.
            </p>
            <div class="form-group">
                <label class="form-label" for="deliverCourierInput">Courier / Collector Name *</label>
                <input type="text" id="deliverCourierInput" class="form-input" required placeholder="e.g. Courier Rider / Rider John">
            </div>
            <div class="form-group">
                <label class="form-label" for="deliverAmountInput">Amount Collected (<?= APP_CURRENCY ?>) *</label>
                <input type="number" id="deliverAmountInput" class="form-input" step="0.01" min="0" required>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="cancelDeliverBtn">Cancel</button>
            <button type="button" class="btn btn-success" id="confirmDeliverBtn">Confirm Delivery & Collection</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Cancel Order / Return Order Reason Dialog                          -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="reasonModal">
    <div class="modal-card" style="max-width: 460px;">
        <div class="modal-header">
            <h3 class="modal-title" id="reasonModalTitle">Cancel Order</h3>
            <button type="button" class="modal-close" id="closeReasonModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning" id="reasonModalNotice">
                Cancelling this order will immediately restore all reserved items to active inventory.
            </div>
            <div class="form-group">
                <label class="form-label" for="reasonTextInput">Reason *</label>
                <textarea id="reasonTextInput" class="form-textarea" rows="3" required placeholder="Please state the reason..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="cancelReasonBtn">Abort</button>
            <button type="button" class="btn btn-danger" id="confirmReasonBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Remit COD Cash to Store (Admin / Manager Only)                    -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="remitModal">
    <div class="modal-card" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title">Confirm Cash Remittance</h3>
            <button type="button" class="modal-close" id="closeRemitModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-success" style="margin-bottom: 12px;">
                You are acknowledging receipt of collected COD cash into the business physical drawer / account.
            </div>
            <p style="font-size: 13.5px; color: var(--text-main);">
                Confirm deposit of <strong id="remitAmountDisplay"><?= APP_CURRENCY ?>0.00</strong> for Order <strong id="remitOrderNumDisplay"></strong>?
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="cancelRemitBtn">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmRemitBtn">Confirm Cash Received</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const currency = '<?= APP_CURRENCY ?>';
    const canRemitRole = <?= $canRemit ? 'true' : 'false' ?>;

    // State
    let currentPage = 1;
    const pageLimit = 15;
    let activeOrderData = null; // Currently viewed order details
    let pendingAction = null;   // For action modals

    // DOM Elements: Filters & Table
    const filterSearch = document.getElementById('filterSearch');
    const filterOrderStatus = document.getElementById('filterOrderStatus');
    const filterPaymentStatus = document.getElementById('filterPaymentStatus');
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');
    const applyFiltersBtn = document.getElementById('applyFiltersBtn');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');

    const ordersTableBody = document.getElementById('ordersTableBody');
    const paginationBar = document.getElementById('paginationBar');
    const paginationInfo = document.getElementById('paginationInfo');
    const pageIndicator = document.getElementById('pageIndicator');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');

    // KPI Elements
    const kpiPending = document.getElementById('kpiPending');
    const kpiDispatched = document.getElementById('kpiDispatched');
    const kpiDelivered = document.getElementById('kpiDelivered');
    const kpiUnremitted = document.getElementById('kpiUnremitted');

    // Drawer Elements
    const orderDetailModal = document.getElementById('orderDetailModal');
    const drawerOrderNumber = document.getElementById('drawerOrderNumber');
    const drawerOrderStatusBadge = document.getElementById('drawerOrderStatusBadge');
    const drawerPaymentStatusBadge = document.getElementById('drawerPaymentStatusBadge');
    const drawerModalBody = document.getElementById('drawerModalBody');
    const drawerModalFooter = document.getElementById('drawerModalFooter');
    const closeDetailModalBtn = document.getElementById('closeDetailModalBtn');

    // Deliver Modal Elements
    const deliverModal = document.getElementById('deliverModal');
    const deliverCourierInput = document.getElementById('deliverCourierInput');
    const deliverAmountInput = document.getElementById('deliverAmountInput');
    const confirmDeliverBtn = document.getElementById('confirmDeliverBtn');
    const cancelDeliverBtn = document.getElementById('cancelDeliverBtn');
    const closeDeliverModalBtn = document.getElementById('closeDeliverModalBtn');

    // Reason Modal Elements
    const reasonModal = document.getElementById('reasonModal');
    const reasonModalTitle = document.getElementById('reasonModalTitle');
    const reasonModalNotice = document.getElementById('reasonModalNotice');
    const reasonTextInput = document.getElementById('reasonTextInput');
    const confirmReasonBtn = document.getElementById('confirmReasonBtn');
    const cancelReasonBtn = document.getElementById('cancelReasonBtn');
    const closeReasonModalBtn = document.getElementById('closeReasonModalBtn');

    // Remit Modal Elements
    const remitModal = document.getElementById('remitModal');
    const remitAmountDisplay = document.getElementById('remitAmountDisplay');
    const remitOrderNumDisplay = document.getElementById('remitOrderNumDisplay');
    const confirmRemitBtn = document.getElementById('confirmRemitBtn');
    const cancelRemitBtn = document.getElementById('cancelRemitBtn');
    const closeRemitModalBtn = document.getElementById('closeRemitModalBtn');

    // Utilities
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatCurrency(amount) {
        return currency + (Number(amount) || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function getStatusBadge(status) {
        switch (status) {
            case 'CONFIRMED':  return '<span class="badge badge-confirmed">Confirmed</span>';
            case 'DISPATCHED': return '<span class="badge badge-dispatched">Dispatched</span>';
            case 'DELIVERED':  return '<span class="badge badge-delivered">Delivered</span>';
            case 'CANCELLED':  return '<span class="badge badge-cancelled">Cancelled</span>';
            case 'RETURNED':   return '<span class="badge badge-danger">Returned</span>';
            default:           return `<span class="badge badge-pending">${escapeHtml(status)}</span>`;
        }
    }

    function getPaymentBadge(status) {
        switch (status) {
            case 'PENDING_COLLECTION': return '<span class="badge badge-pending">Pending Collection</span>';
            case 'COLLECTED':          return '<span class="badge badge-success">Cash Collected</span>';
            case 'REMITTED':           return '<span class="badge badge-remitted">Remitted</span>';
            case 'FAILED':             return '<span class="badge badge-danger">Failed</span>';
            default:                   return `<span class="badge">${escapeHtml(status)}</span>`;
        }
    }

    function openModal(el) {
        el.classList.add('active');
    }

    function closeModal(el) {
        el.classList.remove('active');
    }

    // 1. Fetch Orders List
    async function loadOrders(page = 1) {
        currentPage = page;
        ordersTableBody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center" style="padding: 32px; color: var(--text-muted);">
                    Loading order records...
                </td>
            </tr>
        `;

        const params = new URLSearchParams({
            action: 'list_orders',
            page: currentPage,
            limit: pageLimit,
            search: filterSearch.value.trim(),
            order_status: filterOrderStatus.value,
            payment_status: filterPaymentStatus.value,
            date_from: filterDateFrom.value,
            date_to: filterDateTo.value
        });

        try {
            const res = await API.get(`handler.php?${params.toString()}`);

            if (!res || !res.success) {
                ordersTableBody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center" style="padding: 32px; color: var(--color-danger);">
                            ${escapeHtml(res?.message || 'Failed to retrieve orders.')}
                        </td>
                    </tr>
                `;
                return;
            }

            const data = res.data;
            const orders = data.orders || [];
            const pagination = data.pagination || { total: 0, page: 1, limit: 15, total_pages: 1 };
            const kpi = data.kpi || {};

            // Update Top KPIs
            if (kpi.pending_count !== undefined) kpiPending.textContent = Number(kpi.pending_count).toLocaleString();
            if (kpi.dispatched_count !== undefined) kpiDispatched.textContent = Number(kpi.dispatched_count).toLocaleString();
            if (kpi.delivered_count !== undefined) kpiDelivered.textContent = Number(kpi.delivered_count).toLocaleString();
            if (kpi.unremitted_cod_cash !== undefined) kpiUnremitted.textContent = formatCurrency(kpi.unremitted_cod_cash);

            if (orders.length === 0) {
                ordersTableBody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center" style="padding: 48px 16px; color: var(--text-muted);">
                            <div style="font-size: 28px; margin-bottom: 6px;">📦</div>
                            <div style="font-weight: 600; color: var(--text-main);">No orders found</div>
                            <div style="font-size: 12px; margin-top: 2px;">Try adjusting search keywords or active filters.</div>
                        </td>
                    </tr>
                `;
                paginationBar.style.display = 'none';
                return;
            }

            ordersTableBody.innerHTML = '';
            orders.forEach(o => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight: 700; color: var(--color-primary);">${escapeHtml(o.order_number)}</td>
                    <td>
                        <div style="font-size: 13px; font-weight: 500;">${escapeHtml(o.created_at)}</div>
                        <div style="font-size: 11.5px; color: var(--text-muted);">By: ${escapeHtml(o.creator_name || o.creator_username || 'Staff')}</div>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: var(--text-main);">${escapeHtml(o.customer_name)}</div>
                        <div style="font-size: 12px; color: var(--text-muted);">${escapeHtml(o.customer_phone)}</div>
                        <div style="font-size: 11.5px; color: var(--text-muted); max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(o.delivery_address)}">${escapeHtml(o.delivery_address)}</div>
                    </td>
                    <td>
                        <div style="font-size: 12.5px; max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(o.items_summary || '')}">
                            ${escapeHtml(o.items_summary || 'No items')}
                        </div>
                        <div style="font-size: 11px; color: var(--text-muted);">${o.items_count || 1} line item(s)</div>
                    </td>
                    <td class="text-right tabular-nums" style="font-weight: 700; font-size: 14px;">
                        ${formatCurrency(o.total_amount)}
                    </td>
                    <td class="text-center">${getStatusBadge(o.order_status)}</td>
                    <td class="text-center">${getPaymentBadge(o.payment_status)}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-outline btn-sm view-details-btn" data-id="${o.id}">
                            View Details
                        </button>
                    </td>
                `;

                tr.querySelector('.view-details-btn').addEventListener('click', () => openOrderDetails(o.id));
                ordersTableBody.appendChild(tr);
            });

            // Pagination Controls
            paginationBar.style.display = 'flex';
            const startRecord = (pagination.page - 1) * pagination.limit + 1;
            const endRecord = Math.min(pagination.page * pagination.limit, pagination.total);
            paginationInfo.textContent = `Showing ${startRecord} to ${endRecord} of ${pagination.total} orders`;
            pageIndicator.textContent = `Page ${pagination.page} of ${pagination.total_pages}`;
            prevPageBtn.disabled = pagination.page <= 1;
            nextPageBtn.disabled = pagination.page >= pagination.total_pages;

        } catch (err) {
            ordersTableBody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center" style="padding: 32px; color: var(--color-danger);">
                        A network or parsing error occurred.
                    </td>
                </tr>
            `;
        }
    }

    // 2. Open Order Details Modal / Drawer
    async function openOrderDetails(orderId) {
        drawerOrderNumber.textContent = 'Loading...';
        drawerOrderStatusBadge.style.display = 'none';
        drawerPaymentStatusBadge.style.display = 'none';
        drawerModalBody.innerHTML = `
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                Fetching order details...
            </div>
        `;
        drawerModalFooter.innerHTML = '';
        openModal(orderDetailModal);

        try {
            const res = await API.get(`handler.php?action=get_order_details&id=${orderId}`);

            if (!res || !res.success) {
                drawerModalBody.innerHTML = `
                    <div class="alert alert-danger">
                        ${escapeHtml(res?.message || 'Unable to load order details.')}
                    </div>
                `;
                return;
            }

            activeOrderData = res.data;
            const order = activeOrderData.order;
            const items = activeOrderData.items || [];
            const cod = activeOrderData.cod_payment || {};
            const notes = activeOrderData.notes || [];
            const actions = activeOrderData.permitted_actions || [];

            // Title & Badges
            drawerOrderNumber.textContent = order.order_number;
            drawerOrderStatusBadge.outerHTML = `<span id="drawerOrderStatusBadge">${getStatusBadge(order.order_status)}</span>`;
            drawerPaymentStatusBadge.outerHTML = `<span id="drawerPaymentStatusBadge">${getPaymentBadge(order.payment_status)}</span>`;

            // Build Drawer Body Content
            let itemsRowsHtml = '';
            items.forEach(itm => {
                itemsRowsHtml += `
                    <tr>
                        <td style="font-weight: 500;">
                            <div>${escapeHtml(itm.product_name)}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">${escapeHtml(itm.sku || '')}</div>
                        </td>
                        <td class="text-right tabular-nums">${formatCurrency(itm.unit_price)}</td>
                        <td class="text-center tabular-nums">${itm.quantity}</td>
                        <td class="text-right tabular-nums" style="font-weight: 600;">${formatCurrency(itm.line_total)}</td>
                    </tr>
                `;
            });

            let notesListHtml = '';
            if (notes.length === 0) {
                notesListHtml = '<div style="font-size: 12px; color: var(--text-muted); padding: 8px 0;">No notes logged for this order.</div>';
            } else {
                notes.forEach(n => {
                    notesListHtml += `
                        <div class="timeline-item">
                            <div class="timeline-meta">
                                <span><strong>${escapeHtml(n.author_name || n.author_username || 'Staff')}</strong> (${escapeHtml(n.author_role || 'Staff')})</span>
                                <span>${escapeHtml(n.created_at)}</span>
                            </div>
                            <div class="timeline-text">${escapeHtml(n.note)}</div>
                        </div>
                    `;
                });
            }

            drawerModalBody.innerHTML = `
                <!-- Cancellation Banner if Cancelled or Returned -->
                ${(order.order_status === 'CANCELLED' || order.order_status === 'RETURNED') ? `
                    <div class="alert alert-danger" style="margin-bottom: 16px;">
                        <strong>Order ${order.order_status}:</strong> ${escapeHtml(order.cancellation_reason || 'No specific reason given')}
                    </div>
                ` : ''}

                <!-- Customer Details & COD Summary -->
                <div class="order-detail-grid">
                    <div class="detail-info-block">
                        <div class="detail-info-title">Recipient & Delivery Info</div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Customer Name:</span>
                            <span class="detail-info-val">${escapeHtml(order.customer_name)}</span>
                        </div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Phone Number:</span>
                            <span class="detail-info-val">${escapeHtml(order.customer_phone)}</span>
                        </div>
                        <div class="detail-info-row" style="align-items: flex-start;">
                            <span class="detail-info-label">Address:</span>
                            <span class="detail-info-val" style="text-align: right; max-width: 65%;">${escapeHtml(order.delivery_address)}</span>
                        </div>
                        ${order.delivery_notes ? `
                            <div class="detail-info-row">
                                <span class="detail-info-label">Notes:</span>
                                <span class="detail-info-val" style="text-align: right; font-style: italic;">${escapeHtml(order.delivery_notes)}</span>
                            </div>
                        ` : ''}
                        <div class="detail-info-row" style="margin-top: 8px; border-top: 1px dashed var(--border-color); padding-top: 6px;">
                            <span class="detail-info-label">Created By:</span>
                            <span class="detail-info-val">${escapeHtml(order.creator_name || order.creator_username || 'Staff')} on ${escapeHtml(order.created_at)}</span>
                        </div>
                    </div>

                    <div class="detail-info-block">
                        <div class="detail-info-title">Cash on Delivery (COD) Tracking</div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">COD Status:</span>
                            <span class="detail-info-val">${escapeHtml(cod.status || order.payment_status)}</span>
                        </div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Amount Due:</span>
                            <span class="detail-info-val" style="font-size: 15px; color: var(--color-primary);">${formatCurrency(order.total_amount)}</span>
                        </div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Amount Collected:</span>
                            <span class="detail-info-val" style="color: var(--color-success);">${formatCurrency(cod.amount_collected || 0)}</span>
                        </div>
                        ${cod.collected_by ? `
                            <div class="detail-info-row">
                                <span class="detail-info-label">Collected By:</span>
                                <span class="detail-info-val">${escapeHtml(cod.collected_by)} (${escapeHtml(cod.collected_at || '')})</span>
                            </div>
                        ` : ''}
                        ${cod.status === 'REMITTED' ? `
                            <div class="detail-info-row">
                                <span class="detail-info-label">Store Remittance:</span>
                                <span class="detail-info-val" style="color: #047857;">Remitted to ${escapeHtml(cod.remitted_to_user_name || 'Store')} on ${escapeHtml(cod.remitted_at || '')}</span>
                            </div>
                        ` : ''}
                    </div>
                </div>

                <!-- Line Items Table -->
                <div style="margin-bottom: 20px;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.03em;">
                        Order Line Items (${items.length})
                    </div>
                    <div class="table-responsive">
                        <table class="table" style="font-size: 13px;">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-right">Unit Price</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-right">Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${itemsRowsHtml}
                            </tbody>
                        </table>
                    </div>

                    <!-- Pricing Summary -->
                    <div style="display: flex; justify-content: flex-end; margin-top: 10px;">
                        <div style="width: 260px; font-size: 13px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span style="color: var(--text-muted);">Subtotal:</span>
                                <span class="tabular-nums" style="font-weight: 600;">${formatCurrency(order.subtotal)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span style="color: var(--text-muted);">Discount:</span>
                                <span class="tabular-nums" style="color: var(--color-danger);">- ${formatCurrency(order.discount_amount)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                                <span style="color: var(--text-muted);">Delivery Fee:</span>
                                <span class="tabular-nums" style="font-weight: 600;">+ ${formatCurrency(order.delivery_fee)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; border-top: 2px solid var(--border-color); padding-top: 6px; font-size: 15px; font-weight: 700; color: var(--color-primary);">
                                <span>COD Total Due:</span>
                                <span class="tabular-nums">${formatCurrency(order.total_amount)}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notes & Communication Timeline -->
                <div style="border-top: 1px solid var(--border-color); padding-top: 16px;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-main); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.03em;">
                        Order Activity & Delivery Timeline
                    </div>
                    <div class="timeline-list" id="drawerTimelineList">
                        ${notesListHtml}
                    </div>

                    <!-- Quick Add Note Form -->
                    <div style="display: flex; gap: 8px; align-items: flex-start; margin-top: 8px;">
                        <textarea 
                            id="quickNoteInput" 
                            class="form-textarea" 
                            rows="1" 
                            placeholder="Add communication or courier note..." 
                            style="flex: 1; resize: none; font-size: 13px; padding: 7px 10px;"
                        ></textarea>
                        <button type="button" class="btn btn-outline btn-sm" id="addNoteBtn" style="padding: 7px 14px;">
                            + Add Note
                        </button>
                    </div>
                </div>
            `;

            // Hook Add Note Button
            const quickNoteInput = document.getElementById('quickNoteInput');
            const addNoteBtn = document.getElementById('addNoteBtn');
            addNoteBtn.addEventListener('click', async () => {
                const noteText = quickNoteInput.value.trim();
                if (!noteText) {
                    API.toast('Please write note text before submitting.', 'warning');
                    return;
                }

                addNoteBtn.disabled = true;
                addNoteBtn.textContent = 'Adding...';

                const noteRes = await API.post('handler.php', {
                    action: 'add_note',
                    id: order.id,
                    note: noteText
                });

                addNoteBtn.disabled = false;
                addNoteBtn.textContent = '+ Add Note';

                if (noteRes && noteRes.success) {
                    API.toast('Note added to order.', 'success');
                    quickNoteInput.value = '';
                    openOrderDetails(order.id); // Refresh drawer
                } else {
                    API.toast(noteRes?.message || 'Failed to add note.', 'error');
                }
            });

            // Build Footer Actions based on RBAC & Permitted Actions
            drawerModalFooter.innerHTML = '';

            // Printable Invoice / Receipt Link
            const printReceiptBtn = document.createElement('a');
            printReceiptBtn.href = `<?= BASE_URL ?>/modules/invoices/view.php?order_id=${order.id}`;
            printReceiptBtn.target = '_blank';
            printReceiptBtn.className = 'btn btn-outline';
            printReceiptBtn.innerHTML = '🖨️ View Receipt';
            drawerModalFooter.appendChild(printReceiptBtn);

            // Workflow: CONFIRMED -> DISPATCHED
            if (actions.includes('dispatch')) {
                const dispatchBtn = document.createElement('button');
                dispatchBtn.type = 'button';
                dispatchBtn.className = 'btn btn-primary';
                dispatchBtn.innerHTML = '🚚 Mark as Dispatched';
                dispatchBtn.addEventListener('click', () => updateOrderStatus(order.id, 'DISPATCHED'));
                drawerModalFooter.appendChild(dispatchBtn);
            }

            // Workflow: DISPATCHED -> DELIVERED
            if (actions.includes('deliver')) {
                const deliverBtn = document.createElement('button');
                deliverBtn.type = 'button';
                deliverBtn.className = 'btn btn-success';
                deliverBtn.innerHTML = '✅ Mark as Delivered';
                deliverBtn.addEventListener('click', () => {
                    deliverCourierInput.value = '';
                    deliverAmountInput.value = order.total_amount;
                    openModal(deliverModal);
                });
                drawerModalFooter.appendChild(deliverBtn);
            }

            // Workflow: DISPATCHED -> RETURNED
            if (actions.includes('return')) {
                const returnBtn = document.createElement('button');
                returnBtn.type = 'button';
                returnBtn.className = 'btn btn-outline';
                returnBtn.style.color = 'var(--color-danger)';
                returnBtn.innerHTML = '↩ Mark as Returned';
                returnBtn.addEventListener('click', () => {
                    pendingAction = 'RETURNED';
                    reasonModalTitle.textContent = 'Mark Order as Returned';
                    reasonModalNotice.textContent = 'Returned packages will immediately restore items to inventory with an official movement log.';
                    reasonTextInput.value = '';
                    openModal(reasonModal);
                });
                drawerModalFooter.appendChild(returnBtn);
            }

            // Workflow: DELIVERED -> REMITTED (Strict Manager/Admin only!)
            if (actions.includes('remit') && canRemitRole) {
                const remitBtn = document.createElement('button');
                remitBtn.type = 'button';
                remitBtn.className = 'btn btn-primary';
                remitBtn.style.background = '#047857';
                remitBtn.innerHTML = '💰 Remit Cash to Store';
                remitBtn.addEventListener('click', () => {
                    remitOrderNumDisplay.textContent = order.order_number;
                    remitAmountDisplay.textContent = formatCurrency(order.total_amount);
                    openModal(remitModal);
                });
                drawerModalFooter.appendChild(remitBtn);
            }

            // Workflow: Cancel (Pre-dispatch)
            if (actions.includes('cancel')) {
                const cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'btn btn-danger';
                cancelBtn.innerHTML = '✕ Cancel Order';
                cancelBtn.addEventListener('click', () => {
                    pendingAction = 'CANCELLED';
                    reasonModalTitle.textContent = 'Cancel Order';
                    reasonModalNotice.textContent = 'Cancelling will mark the order void and restore reserved items back to active stock.';
                    reasonTextInput.value = '';
                    openModal(reasonModal);
                });
                drawerModalFooter.appendChild(cancelBtn);
            }

        } catch (err) {
            drawerModalBody.innerHTML = `
                <div class="alert alert-danger">
                    An error occurred while loading order details.
                </div>
            `;
        }
    }

    // 3. Status Transition Helper
    async function updateOrderStatus(orderId, status, extra = {}) {
        const payload = {
            action: 'update_status',
            id: orderId,
            status,
            ...extra
        };

        const res = await API.post('handler.php', payload);
        if (res && res.success) {
            API.toast(res.message || 'Status updated successfully.', 'success');
            closeModal(orderDetailModal);
            loadOrders(currentPage);
        } else {
            API.toast(res?.message || 'Failed to update order status.', 'error');
        }
    }

    // 4. Modal Confirmations
    // Delivery Confirm
    confirmDeliverBtn.addEventListener('click', async () => {
        if (!activeOrderData) return;
        const courier = deliverCourierInput.value.trim();
        const amount = parseFloat(deliverAmountInput.value) || 0;

        if (!courier) {
            API.toast('Please input the courier / collector name.', 'warning');
            deliverCourierInput.focus();
            return;
        }

        confirmDeliverBtn.disabled = true;
        confirmDeliverBtn.textContent = 'Updating...';

        await updateOrderStatus(activeOrderData.order.id, 'DELIVERED', {
            collected_by: courier,
            amount_collected: amount
        });

        confirmDeliverBtn.disabled = false;
        confirmDeliverBtn.textContent = 'Confirm Delivery & Collection';
        closeModal(deliverModal);
    });

    // Reason Confirm (Cancel or Return)
    confirmReasonBtn.addEventListener('click', async () => {
        if (!activeOrderData || !pendingAction) return;
        const reason = reasonTextInput.value.trim();

        if (!reason) {
            API.toast('Please provide a reason.', 'warning');
            reasonTextInput.focus();
            return;
        }

        confirmReasonBtn.disabled = true;
        confirmReasonBtn.textContent = 'Processing...';

        await updateOrderStatus(activeOrderData.order.id, pendingAction, {
            reason
        });

        confirmReasonBtn.disabled = false;
        confirmReasonBtn.textContent = 'Confirm';
        closeModal(reasonModal);
        pendingAction = null;
    });

    // Remit Confirm
    confirmRemitBtn.addEventListener('click', async () => {
        if (!activeOrderData) return;

        confirmRemitBtn.disabled = true;
        confirmRemitBtn.textContent = 'Remitting...';

        await updateOrderStatus(activeOrderData.order.id, 'REMITTED');

        confirmRemitBtn.disabled = false;
        confirmRemitBtn.textContent = 'Confirm Cash Received';
        closeModal(remitModal);
    });

    // Close Modals
    closeDetailModalBtn.addEventListener('click', () => closeModal(orderDetailModal));
    closeDeliverModalBtn.addEventListener('click', () => closeModal(deliverModal));
    cancelDeliverBtn.addEventListener('click', () => closeModal(deliverModal));
    closeReasonModalBtn.addEventListener('click', () => closeModal(reasonModal));
    cancelReasonBtn.addEventListener('click', () => closeModal(reasonModal));
    closeRemitModalBtn.addEventListener('click', () => closeModal(remitModal));
    cancelRemitBtn.addEventListener('click', () => closeModal(remitModal));

    // Filter Controls
    applyFiltersBtn.addEventListener('click', () => loadOrders(1));
    resetFiltersBtn.addEventListener('click', () => {
        filterSearch.value = '';
        filterOrderStatus.value = '';
        filterPaymentStatus.value = '';
        filterDateFrom.value = '';
        filterDateTo.value = '';
        loadOrders(1);
    });

    filterSearch.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            loadOrders(1);
        }
    });

    // Pagination Click
    prevPageBtn.addEventListener('click', () => {
        if (currentPage > 1) loadOrders(currentPage - 1);
    });

    nextPageBtn.addEventListener('click', () => {
        loadOrders(currentPage + 1);
    });

    // Initial Load
    loadOrders(1);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
