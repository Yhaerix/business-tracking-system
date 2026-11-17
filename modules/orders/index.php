<?php
/**
 * Orders Management Dashboard & Detail Drawer (COD Workflow)
 * Small Business Sales and Expense Tracking System
 *
 * File: modules/orders/index.php
 * Reference: new-design.html (View 3, Modals 1, 2, 3, 12)
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

<!-- Action Header -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0;">Orders &amp; Delivery Management</h1>
        <p style="font-size: 13.5px; color: var(--text-muted); margin: 4px 0 0 0;">
            Manage Cash-on-Delivery (COD) lifecycles, dispatch couriers, track collected cash, and verify store remittance.
        </p>
    </div>

    <div>
        <?php if ($canCreate): ?>
            <a href="<?= BASE_URL ?>/modules/orders/create.php" class="btn btn-primary">
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
        <div class="stat-value tabular-nums" id="kpiPending"><?= number_format((int)$initialKpi['pending_count']) ?></div>
        <div class="stat-subtext">Awaiting courier dispatch</div>
    </div>

    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Dispatched / In Transit</span>
            <div class="stat-icon icon-primary">🚚</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiDispatched"><?= number_format((int)$initialKpi['dispatched_count']) ?></div>
        <div class="stat-subtext">Out for COD delivery</div>
    </div>

    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Delivered (Collected)</span>
            <div class="stat-icon icon-success">✅</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiDelivered"><?= number_format((int)$initialKpi['delivered_count']) ?></div>
        <div class="stat-subtext">Cash received by courier</div>
    </div>

    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Unremitted COD Cash</span>
            <div class="stat-icon icon-warning">💰</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiUnremitted" style="color: #b45309;">
            <?= APP_CURRENCY ?><?= number_format((float)$initialKpi['unremitted_cod_cash'], 2) ?>
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
            <option value="PENDING">PENDING</option>
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

    <div class="filter-group" style="max-width: 170px;">
        <select id="filterPaymentMode" class="form-select">
            <option value="">All Payment Modes</option>
            <option value="COD">Cash on Delivery (COD)</option>
            <option value="PRESTIGE_COINS">Prestige Coins</option>
        </select>
    </div>

    <div class="filter-group" style="max-width: 150px;">
        <input type="date" id="filterDateFrom" class="form-input" title="Date From">
    </div>

    <div class="filter-group" style="max-width: 150px;">
        <input type="date" id="filterDateTo" class="form-input" title="Date To">
    </div>

    <div class="filter-group-fixed" style="display: flex; gap: 8px;">
        <button type="button" id="applyFiltersBtn" class="btn btn-primary btn-sm">Filter</button>
        <button type="button" id="resetFiltersBtn" class="btn btn-outline btn-sm">Reset</button>
    </div>
</div>

<!-- Orders Data Table Container -->
<style>
/* Clean & Scannable Orders Table Styling */
#ordersTable {
    width: 100%;
    border-collapse: collapse;
}

#ordersTable thead th {
    background: #f8fafc;
    color: #475569;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}

#ordersTable tbody tr.order-row {
    cursor: pointer;
    transition: background-color 0.15s ease;
    border-bottom: 1px solid #f1f5f9;
}

#ordersTable tbody tr.order-row:hover {
    background-color: #f8fafc;
}

#ordersTable tbody td {
    padding: 14px 16px;
    vertical-align: middle;
    font-size: 13px;
}

.order-num-pill {
    display: inline-block;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-weight: 700;
    font-size: 12.5px;
    color: var(--color-primary);
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    padding: 4px 8px;
    border-radius: 6px;
    text-decoration: none;
    transition: all 0.15s ease;
}

.order-num-pill:hover {
    background: #dbeafe;
    border-color: #93c5fd;
    color: #1d4ed8;
}

.btn-order-view {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 14px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    cursor: pointer;
    transition: all 0.15s ease;
}

.btn-order-view:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a;
}

/* Status & Payment Pill Badges */
.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    line-height: 1.2;
    white-space: nowrap;
}

.badge-pending,
.badge-warning {
    background: #fef3c7 !important;
    color: #92400e !important;
    border: 1px solid #fde68a !important;
}

.badge-confirmed,
.badge-primary,
.badge-info {
    background: #e0f2fe !important;
    color: #0369a1 !important;
    border: 1px solid #bae6fd !important;
}

.badge-dispatched {
    background: #f3e8ff !important;
    color: #6b21a8 !important;
    border: 1px solid #e9d5ff !important;
}

.badge-delivered,
.badge-success {
    background: #dcfce7 !important;
    color: #166534 !important;
    border: 1px solid #bbf7d0 !important;
}

.badge-cancelled,
.badge-danger {
    background: #fee2e2 !important;
    color: #991b1b !important;
    border: 1px solid #fecaca !important;
}
</style>

<div class="card" style="padding: 0; overflow: hidden; margin-bottom: 24px;">
    <div class="table-responsive">
        <table class="table" id="ordersTable">
            <thead>
                <tr>
                    <th style="width: 140px;">Order #</th>
                    <th style="width: 125px;">Date</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th class="text-right" style="width: 125px;">Total Due</th>
                    <th class="text-center" style="width: 130px;">Order Status</th>
                    <th class="text-center" style="width: 140px;">Payment Status</th>
                    <th class="text-center" style="width: 90px;">Action</th>
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
    </div>

    <!-- Pagination Controls -->
    <div class="pagination-container" id="paginationBar" style="display: none; padding: 14px 20px; border-top: 1px solid var(--border-color);">
        <div id="paginationInfo">Showing 0 of 0 orders</div>
        <div class="pagination-controls">
            <button type="button" class="btn btn-outline btn-sm" id="prevPageBtn">&larr; Previous</button>
            <span id="pageIndicator" style="font-weight: 600; padding: 0 4px;">Page 1 of 1</span>
            <button type="button" class="btn btn-outline btn-sm" id="nextPageBtn">Next &rarr;</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 1. MODAL: Order Details & COD Lifecycle Drawer                            -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="orderDrawerModal">
    <div class="modal-card order-drawer-card">
        <div class="modal-header">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <h3 class="modal-title" id="drawerOrderNumber" style="margin: 0; font-size: 18px;">Order Details</h3>
                <span id="drawerOrderStatusBadge" class="badge">STATUS</span>
                <span id="drawerPaymentStatusBadge" class="badge">COD</span>
            </div>
            <button type="button" class="modal-close" onclick="window.closeModal('orderDrawerModal')">&times;</button>
        </div>

        <div class="modal-body" id="drawerModalBody" style="padding: 24px;">
            <!-- Rendered dynamically -->
        </div>

        <div class="modal-footer" id="drawerModalFooter" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <!-- Dynamic state machine buttons injected here -->
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 2. MODAL: Mark Delivered / Courier Prompt                                 -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="deliverModal">
    <div class="modal-card" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title">📦 Record Courier Delivery</h3>
            <button type="button" class="modal-close" onclick="window.closeModal('deliverModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="markDeliveredForm" onsubmit="return false;">
                <div class="form-group">
                    <label class="form-label" for="deliverCourierInput">Courier / Collector Name *</label>
                    <input type="text" id="deliverCourierInput" class="form-input" required placeholder="e.g. Lalamove Rider J. Perez">
                </div>
                <div class="form-group">
                    <label class="form-label" for="deliverAmountInput">Amount Collected (<?= APP_CURRENCY ?>) *</label>
                    <input type="number" id="deliverAmountInput" class="form-input" step="0.01" min="0" required>
                    <small style="color: var(--text-muted); font-size: 11.5px; margin-top: 4px; display: block;">
                        Verify that physical cash matches the COD Total Due before recording.
                    </small>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="window.closeModal('deliverModal')">Cancel</button>
            <button type="button" class="btn btn-success" id="confirmDeliverBtn">Confirm Delivery &amp; Collection</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 3. MODAL: Cancel Order / Void Prompt                                      -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="reasonModal">
    <div class="modal-card" style="max-width: 460px;">
        <div class="modal-header">
            <h3 class="modal-title" id="reasonModalTitle" style="color: var(--color-danger);">⚠️ Cancel COD Order</h3>
            <button type="button" class="modal-close" onclick="window.closeModal('reasonModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning" id="reasonModalNotice" style="margin-bottom: 14px;">
                Cancelling will restore deducted product inventory back to stock and mark associated invoices as VOID.
            </div>
            <div class="form-group">
                <label class="form-label" for="reasonTextInput">Reason *</label>
                <textarea id="reasonTextInput" class="form-textarea" rows="3" required placeholder="State reason for customer cancellation or failed delivery..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="window.closeModal('reasonModal')">Abort</button>
            <button type="button" class="btn btn-danger" id="confirmReasonBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 4. MODAL: Confirm Cash Remittance (Admin / Manager Only)                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="remitModal">
    <div class="modal-card" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title">Confirm Cash Remittance</h3>
            <button type="button" class="modal-close" onclick="window.closeModal('remitModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-success" style="margin-bottom: 14px;">
                You are acknowledging receipt of collected COD cash into the business physical drawer / account.
            </div>
            <p style="font-size: 13.5px; color: var(--text-main); margin-bottom: 14px;">
                Confirm deposit of <strong id="remitAmountDisplay" style="color: var(--color-primary);"><?= APP_CURRENCY ?>0.00</strong> for Order <strong id="remitOrderNumDisplay"></strong>?
            </p>
            <div class="form-group">
                <label class="form-label" for="remitNotesInput">Remittance Reference / Notes</label>
                <textarea id="remitNotesInput" class="form-textarea" rows="2" placeholder="e.g. Courier turnover envelope #8812 verified by Manager"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="window.closeModal('remitModal')">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmRemitBtn">Confirm Cash Received</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 5. MODAL: Assign Courier & Dispatch Waybill (Phase 6 Fulfillment)         -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="assignCourierModal">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">🚚 Assign Courier &amp; Waybill Tracking</h3>
            <button type="button" class="modal-close" onclick="window.closeModal('assignCourierModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="assignCourierForm" onsubmit="return false;">
                <input type="hidden" id="courierOrderId">
                <div class="alert alert-info" style="margin-bottom: 16px; font-size: 12.5px;">
                    Enter the logistics partner and tracking code. This will update the customer's live tracking milestone to <strong>DISPATCHED (In Transit)</strong>.
                </div>
                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" for="courierNameInput">Courier Partner *</label>
                    <select id="courierNameInput" class="form-select" required>
                        <option value="J&amp;T Express Philippines">J&amp;T Express Philippines</option>
                        <option value="LBC Express">LBC Express</option>
                        <option value="Flash Express">Flash Express</option>
                        <option value="Standard Insured Delivery">Standard Insured Delivery</option>
                        <option value="Lalamove Same-Day">Lalamove Same-Day</option>
                        <option value="GrabExpress">GrabExpress</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" for="courierTrackingInput">Waybill / Tracking Number *</label>
                    <input type="text" id="courierTrackingInput" class="form-input" required placeholder="e.g. JT-PH-987654321" style="font-family: monospace; font-weight: 700;">
                </div>
                <div class="form-group" style="margin-bottom: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" id="autoDispatchCheck" checked>
                        <span>Automatically advance order status to <strong>DISPATCHED</strong></span>
                    </label>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="window.closeModal('assignCourierModal')">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmAssignCourierBtn">Save Waybill &amp; Dispatch</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const currency = '<?= APP_CURRENCY ?>';
    const canRemitRole = <?= $canRemit ? 'true' : 'false' ?>;
    const canUpdateStatusRole = <?= $canUpdateStatus ? 'true' : 'false' ?>;

    // State
    let currentPage = 1;
    const pageLimit = 15;
    let activeOrderData = null; // Currently viewed order details
    let pendingAction = null;   // For action modals

    // DOM Elements: Filters & Table
    const filterSearch = document.getElementById('filterSearch');
    const filterOrderStatus = document.getElementById('filterOrderStatus');
    const filterPaymentStatus = document.getElementById('filterPaymentStatus');
    const filterPaymentMode = document.getElementById('filterPaymentMode');
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
    const orderDrawerModal = document.getElementById('orderDrawerModal');
    const drawerOrderNumber = document.getElementById('drawerOrderNumber');
    const drawerOrderStatusBadge = document.getElementById('drawerOrderStatusBadge');
    const drawerPaymentStatusBadge = document.getElementById('drawerPaymentStatusBadge');
    const drawerModalBody = document.getElementById('drawerModalBody');
    const drawerModalFooter = document.getElementById('drawerModalFooter');

    // Deliver Modal Elements
    const deliverModal = document.getElementById('deliverModal');
    const deliverCourierInput = document.getElementById('deliverCourierInput');
    const deliverAmountInput = document.getElementById('deliverAmountInput');
    const confirmDeliverBtn = document.getElementById('confirmDeliverBtn');

    // Assign Courier Modal Elements (Phase 6)
    const assignCourierModal = document.getElementById('assignCourierModal');
    const courierOrderId = document.getElementById('courierOrderId');
    const courierNameInput = document.getElementById('courierNameInput');
    const courierTrackingInput = document.getElementById('courierTrackingInput');
    const autoDispatchCheck = document.getElementById('autoDispatchCheck');
    const confirmAssignCourierBtn = document.getElementById('confirmAssignCourierBtn');

    // Reason Modal Elements
    const reasonModal = document.getElementById('reasonModal');
    const reasonModalTitle = document.getElementById('reasonModalTitle');
    const reasonModalNotice = document.getElementById('reasonModalNotice');
    const reasonTextInput = document.getElementById('reasonTextInput');
    const confirmReasonBtn = document.getElementById('confirmReasonBtn');

    // Remit Modal Elements
    const remitModal = document.getElementById('remitModal');
    const remitAmountDisplay = document.getElementById('remitAmountDisplay');
    const remitOrderNumDisplay = document.getElementById('remitOrderNumDisplay');
    const remitNotesInput = document.getElementById('remitNotesInput');
    const confirmRemitBtn = document.getElementById('confirmRemitBtn');

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

    function formatOrderDate(dateStr) {
        if (!dateStr) return { date: '-', time: '' };
        try {
            const parts = dateStr.trim().split(' ');
            if (parts.length === 2) {
                const [y, m, d] = parts[0].split('-');
                const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const monthIndex = parseInt(m, 10) - 1;
                const formattedDate = (monthIndex >= 0 && monthIndex < 12) 
                    ? `${monthNames[monthIndex]} ${parseInt(d, 10)}, ${y}` 
                    : parts[0];

                const [hh, mm] = parts[1].split(':');
                let hour = parseInt(hh, 10);
                const ampm = hour >= 12 ? 'PM' : 'AM';
                hour = hour % 12;
                if (hour === 0) hour = 12;
                const formattedTime = `${hour}:${mm} ${ampm}`;

                return { date: formattedDate, time: formattedTime };
            }
            return { date: dateStr, time: '' };
        } catch (e) {
            return { date: dateStr, time: '' };
        }
    }

    function getStatusBadge(status) {
        switch (status) {
            case 'CONFIRMED':  return '<span class="badge badge-confirmed">CONFIRMED</span>';
            case 'DISPATCHED': return '<span class="badge badge-dispatched">DISPATCHED</span>';
            case 'DELIVERED':  return '<span class="badge badge-delivered">DELIVERED</span>';
            case 'CANCELLED':  return '<span class="badge badge-cancelled">CANCELLED</span>';
            case 'RETURNED':   return '<span class="badge badge-danger">RETURNED</span>';
            default:           return `<span class="badge badge-pending">${escapeHtml(status)}</span>`;
        }
    }

    function getPaymentBadge(status) {
        switch (status) {
            case 'PENDING_COLLECTION': return '<span class="badge badge-warning">PENDING COLLECTION</span>';
            case 'COLLECTED':          return '<span class="badge badge-primary">COLLECTED</span>';
            case 'REMITTED':           return '<span class="badge badge-success">REMITTED</span>';
            case 'FAILED':             return '<span class="badge badge-danger">FAILED</span>';
            default:                   return `<span class="badge">${escapeHtml(status)}</span>`;
        }
    }

    function renderCodStepper(orderStatus, paymentStatus) {
        const isCancelled = (orderStatus === 'CANCELLED' || orderStatus === 'RETURNED');
        
        let stepPendingState = 'step-completed';
        let stepConfirmedState = '';
        let stepDispatchedState = '';
        let stepDeliveredState = '';
        let stepRemittedState = '';

        if (!isCancelled) {
            if (orderStatus === 'PENDING') {
                stepPendingState = 'step-active';
            } else if (orderStatus === 'CONFIRMED') {
                stepPendingState = 'step-completed';
                stepConfirmedState = 'step-active';
            } else if (orderStatus === 'DISPATCHED') {
                stepPendingState = 'step-completed';
                stepConfirmedState = 'step-completed';
                stepDispatchedState = 'step-active';
            } else if (orderStatus === 'DELIVERED') {
                stepPendingState = 'step-completed';
                stepConfirmedState = 'step-completed';
                stepDispatchedState = 'step-completed';
                if (paymentStatus === 'REMITTED') {
                    stepDeliveredState = 'step-completed';
                    stepRemittedState = 'step-completed step-active';
                } else {
                    stepDeliveredState = 'step-active';
                }
            }
        }

        return `
            <div>
                <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; letter-spacing: 0.05em;">
                    COD Lifecycle Pipeline
                </div>
                <div class="cod-stepper" id="drawerStepper">
                    <div class="step-item ${stepPendingState}" id="step-PENDING">
                        <div class="step-node">${stepPendingState.includes('completed') ? '✓' : '1'}</div>
                        <div class="step-label">Placed</div>
                    </div>
                    <div class="step-item ${stepConfirmedState}" id="step-CONFIRMED">
                        <div class="step-node">${stepConfirmedState.includes('completed') ? '✓' : '2'}</div>
                        <div class="step-label">Confirmed</div>
                    </div>
                    <div class="step-item ${stepDispatchedState}" id="step-DISPATCHED">
                        <div class="step-node">${stepDispatchedState.includes('completed') ? '✓' : (stepDispatchedState.includes('active') ? '🚚' : '3')}</div>
                        <div class="step-label">Dispatched</div>
                    </div>
                    <div class="step-item ${stepDeliveredState}" id="step-DELIVERED">
                        <div class="step-node">${stepDeliveredState.includes('completed') ? '✓' : '4'}</div>
                        <div class="step-label">Delivered</div>
                    </div>
                    <div class="step-item ${stepRemittedState}" id="step-REMITTED">
                        <div class="step-node">${stepRemittedState.includes('completed') ? '✓' : '5'}</div>
                        <div class="step-label">Remitted</div>
                    </div>
                </div>
            </div>
        `;
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
            payment_mode: filterPaymentMode.value,
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
                    <tr class="empty-row">
                        <td colspan="8" style="text-align: center !important; padding: 56px 16px !important; color: var(--text-muted);">
                            <div style="font-size: 32px; margin-bottom: 8px; line-height: 1;">📦</div>
                            <div style="font-weight: 700; color: var(--text-main); font-size: 15px; margin-bottom: 4px;">No orders found</div>
                            <div style="font-size: 12.5px; color: var(--text-muted);">Try adjusting search keywords or active filters.</div>
                        </td>
                    </tr>
                `;
                paginationBar.style.display = 'none';
                return;
            }

            ordersTableBody.innerHTML = '';
            orders.forEach(o => {
                const tr = document.createElement('tr');
                tr.className = 'order-row';
                
                // Formatted Date & Time
                const dateObj = formatOrderDate(o.created_at);
                const staffTip = escapeHtml(o.creator_name || o.creator_username || 'Staff');

                // Items summary
                const itemsCount = parseInt(o.items_count, 10) || 1;
                const countBadgeText = itemsCount === 1 ? '1 item' : `${itemsCount} items`;

                tr.innerHTML = `
                    <td>
                        <a href="javascript:void(0)" class="order-num-pill view-details-link" data-id="${o.id}">
                            ${escapeHtml(o.order_number)}
                        </a>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: var(--text-main); font-size: 13px;" title="Staff: ${staffTip}">
                            ${dateObj.date}
                        </div>
                        <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
                            ${dateObj.time}
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: var(--text-main); font-size: 13.5px;">
                            ${escapeHtml(o.customer_name)}
                        </div>
                        <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                            ${escapeHtml(o.customer_phone || 'No phone')}
                        </div>
                    </td>
                    <td>
                        <span class="badge" style="background:#f1f5f9; color:#334155; font-size:11px; font-weight:600;">
                            ${countBadgeText}
                        </span>
                        ${o.items_summary ? `
                            <div style="font-size: 11.5px; color: var(--text-muted); max-width: 170px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 3px;" title="${escapeHtml(o.items_summary)}">
                                ${escapeHtml(o.items_summary)}
                            </div>
                        ` : ''}
                    </td>
                    <td class="text-right tabular-nums" style="font-weight: 700; font-size: 14.5px; color: var(--text-main);">
                        ${formatCurrency(o.total_amount)}
                    </td>
                    <td class="text-center">
                        ${getStatusBadge(o.order_status)}
                    </td>
                    <td class="text-center">
                        ${getPaymentBadge(o.payment_status)}
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn-order-view view-details-btn" data-id="${o.id}" title="Open Full Order Details Drawer">
                            View
                        </button>
                    </td>
                `;

                // Hook row click to open drawer
                tr.addEventListener('click', (e) => {
                    if (e.target.closest('a') || e.target.closest('button')) {
                        return;
                    }
                    openOrderDetails(o.id);
                });

                // Hook view details
                tr.querySelector('.view-details-link').addEventListener('click', (e) => {
                    e.stopPropagation();
                    openOrderDetails(o.id);
                });
                tr.querySelector('.view-details-btn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    openOrderDetails(o.id);
                });

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
        window.openModal(orderDrawerModal);

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
            drawerOrderStatusBadge.style.display = 'inline-block';
            drawerPaymentStatusBadge.style.display = 'inline-block';
            drawerOrderStatusBadge.outerHTML = `<span id="drawerOrderStatusBadge">${getStatusBadge(order.order_status)}</span>`;
            drawerPaymentStatusBadge.outerHTML = `<span id="drawerPaymentStatusBadge">${getPaymentBadge(order.payment_status)}</span>`;

            // Build Drawer Body Content
            let itemsRowsHtml = '';
            items.forEach(itm => {
                itemsRowsHtml += `
                    <tr>
                        <td>
                            <div style="font-weight: 600;">${escapeHtml(itm.product_name)}</div>
                            <div style="font-size: 11px; font-family: monospace; color: var(--color-primary);">${escapeHtml(itm.sku || '')}</div>
                        </td>
                        <td class="text-right tabular-nums">${formatCurrency(itm.unit_price)}</td>
                        <td class="text-center tabular-nums">${itm.quantity}</td>
                        <td class="text-right tabular-nums" style="font-weight: 600;">${formatCurrency(itm.line_total)}</td>
                    </tr>
                `;
            });

            let notesListHtml = '';
            if (notes.length === 0) {
                notesListHtml = '<div style="font-size: 12px; color: var(--text-muted); padding: 8px 0;">No fulfillment notes recorded yet.</div>';
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
                <!-- 5-Stage Visual COD Pipeline Stepper -->
                ${renderCodStepper(order.order_status, cod.status || order.payment_status)}

                <!-- Cancellation Banner if Cancelled or Returned -->
                ${(order.order_status === 'CANCELLED' || order.order_status === 'RETURNED') ? `
                    <div class="alert alert-danger" style="margin-bottom: 20px;">
                        <strong>Order ${order.order_status}:</strong> ${escapeHtml(order.cancellation_reason || 'No specific reason given')}
                    </div>
                ` : ''}

                <!-- Customer Details & Financial Grid -->
                <div class="order-detail-grid">
                    <div class="detail-info-block">
                        <div class="detail-info-title">Customer &amp; Delivery Details</div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Customer Name:</span>
                            <span class="detail-info-val">${escapeHtml(order.customer_name)}</span>
                        </div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Mobile Phone:</span>
                            <span class="detail-info-val">${escapeHtml(order.customer_phone)}</span>
                        </div>
                        <div class="detail-info-row" style="align-items: flex-start;">
                            <span class="detail-info-label">Delivery Address:</span>
                            <span class="detail-info-val" style="text-align: right; max-width: 65%;">${escapeHtml(order.delivery_address)}</span>
                        </div>
                        ${order.delivery_notes ? `
                            <div class="detail-info-row">
                                <span class="detail-info-label">Delivery Notes:</span>
                                <span class="detail-info-val" style="text-align: right; font-style: italic;">${escapeHtml(order.delivery_notes)}</span>
                            </div>
                        ` : ''}
                        <div class="detail-info-row" style="margin-top: 8px; border-top: 1px dashed var(--border-color); padding-top: 6px;">
                            <span class="detail-info-label">Date Placed:</span>
                            <span class="detail-info-val">${escapeHtml(order.created_at)}</span>
                        </div>
                    </div>

                    <div class="detail-info-block">
                        <div class="detail-info-title">Financial &amp; Logistics Dispatch</div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Payment Mode:</span>
                            <span class="detail-info-val" style="font-weight:700;">${order.payment_method === 'PRESTIGE_COINS' ? '🪙 Prestige Coins' : '💵 Cash on Delivery (COD)'}</span>
                        </div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Order Total:</span>
                            <span class="detail-info-val" style="font-size: 15px; color: var(--color-primary);">${formatCurrency(order.total_amount)}</span>
                        </div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Assigned Courier:</span>
                            <span class="detail-info-val">${escapeHtml(order.courier_name || cod.collected_by || 'Awaiting Courier')}</span>
                        </div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Waybill Tracking #:</span>
                            <span class="detail-info-val" style="font-family: monospace; font-weight: 700; color: var(--color-primary);">${escapeHtml(order.tracking_number || 'N/A')}</span>
                        </div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Payment Status:</span>
                            <span class="detail-info-val">${escapeHtml(cod.status || order.payment_status)}</span>
                        </div>
                        ${cod.status === 'REMITTED' ? `
                            <div class="detail-info-row" style="margin-top: 8px; border-top: 1px dashed var(--border-color); padding-top: 6px;">
                                <span class="detail-info-label">Store Remittance:</span>
                                <span class="detail-info-val" style="color: #047857; font-weight: 700;">Remitted on ${escapeHtml(cod.remitted_at || '')}</span>
                            </div>
                        ` : ''}
                    </div>
                </div>

                <!-- Line Items Table -->
                <div>
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; letter-spacing: 0.04em;">
                        Order Line Items (${items.length})
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-right" style="width: 120px;">Unit Price</th>
                                    <th class="text-center" style="width: 80px;">Qty</th>
                                    <th class="text-right" style="width: 130px;">Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${itemsRowsHtml}
                            </tbody>
                        </table>
                    </div>

                    <!-- Pricing Summary -->
                    <div style="margin-top: 14px; border-top: 1px dashed var(--border-color); padding-top: 12px;">
                        <div class="detail-info-row">
                            <span class="detail-info-label">Subtotal:</span>
                            <span class="detail-info-val tabular-nums">${formatCurrency(order.subtotal)}</span>
                        </div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Discount:</span>
                            <span class="detail-info-val tabular-nums" style="color: var(--color-danger);">- ${formatCurrency(order.discount_amount)}</span>
                        </div>
                        <div class="detail-info-row">
                            <span class="detail-info-label">Delivery Fee:</span>
                            <span class="detail-info-val tabular-nums">+ ${formatCurrency(order.delivery_fee)}</span>
                        </div>
                        <div class="detail-info-row" style="border-top: 2px solid var(--border-color); padding-top: 8px; margin-top: 6px; font-size: 15px; font-weight: 700;">
                            <span style="color: var(--color-primary);">COD Total Due:</span>
                            <span class="detail-info-val tabular-nums" style="color: var(--color-primary);">${formatCurrency(order.total_amount)}</span>
                        </div>
                    </div>
                </div>

                <!-- Notes & Fulfillment History Timeline -->
                <div>
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; letter-spacing: 0.04em;">
                        Fulfillment History &amp; Activity
                    </div>
                    <div class="timeline-list" id="drawerTimelineList">
                        ${notesListHtml}
                    </div>

                    <!-- Quick Add Note Form -->
                    <div style="display: flex; gap: 8px; align-items: center; margin-top: 14px;">
                        <input 
                            type="text" 
                            id="quickNoteInput" 
                            class="form-input" 
                            placeholder="Add communication or courier note..." 
                            style="flex: 1; font-size: 13px; height: 38px;"
                        >
                        <button type="button" class="btn btn-primary btn-sm" id="addNoteBtn" style="height: 38px; padding: 0 16px;">
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
                    window.showToast('Please write note text before submitting.', 'warning');
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
                    window.showToast('Note added to order history.', 'success');
                    quickNoteInput.value = '';
                    openOrderDetails(order.id); // Refresh drawer
                } else {
                    window.showToast(noteRes?.message || 'Failed to add note.', 'danger');
                }
            });

            // Build Footer Actions based on RBAC & Permitted Actions (Clean & Non-duplicated)
            drawerModalFooter.innerHTML = '';
            drawerModalFooter.style.display = 'flex';
            drawerModalFooter.style.justifyContent = 'space-between';
            drawerModalFooter.style.alignItems = 'center';
            drawerModalFooter.style.flexWrap = 'wrap';
            drawerModalFooter.style.gap = '12px';
            drawerModalFooter.style.width = '100%';

            // Left Group: Document Links (Packing Slip & View Invoice only - no duplicate thermal buttons)
            const leftActionWrap = document.createElement('div');
            leftActionWrap.style.display = 'flex';
            leftActionWrap.style.gap = '8px';
            leftActionWrap.style.alignItems = 'center';

            leftActionWrap.innerHTML = `
                <a href="packing-slip.php?id=${order.id}" target="_blank" class="btn btn-outline btn-sm" style="font-weight: 500; font-size: 12.5px;">
                    📦 Packing Slip
                </a>
                <a href="<?= BASE_URL ?>/modules/invoices/view.php?order_id=${order.id}" target="_blank" class="btn btn-outline btn-sm" style="font-weight: 500; font-size: 12.5px;">
                    📄 View Invoice
                </a>
            `;
            drawerModalFooter.appendChild(leftActionWrap);

            // Right Group: Contextual Operational Buttons
            const rightActionWrap = document.createElement('div');
            rightActionWrap.style.display = 'flex';
            rightActionWrap.style.gap = '8px';
            rightActionWrap.style.alignItems = 'center';
            rightActionWrap.style.flexWrap = 'wrap';

            // 1. Destructive/Cancel Action (placed subtly on secondary side)
            if (actions.includes('cancel')) {
                const cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'btn btn-outline btn-sm';
                cancelBtn.style.color = 'var(--color-danger)';
                cancelBtn.style.borderColor = '#fca5a5';
                cancelBtn.style.background = '#fff5f5';
                cancelBtn.style.fontWeight = '500';
                cancelBtn.style.fontSize = '12.5px';
                cancelBtn.innerHTML = '✕ Cancel Order';
                cancelBtn.addEventListener('click', () => {
                    pendingAction = 'CANCELLED';
                    reasonModalTitle.textContent = 'Cancel COD Order';
                    reasonModalNotice.textContent = 'Cancelling will mark the order void and restore reserved items back to active stock.';
                    reasonTextInput.value = '';
                    window.openModal(reasonModal);
                });
                rightActionWrap.appendChild(cancelBtn);
            }

            // 2. Logistics: Assign Courier Waybill
            if (canUpdateStatusRole && order.order_status !== 'DELIVERED' && order.order_status !== 'CANCELLED') {
                const assignBtn = document.createElement('button');
                assignBtn.type = 'button';
                assignBtn.className = 'btn btn-outline btn-sm';
                assignBtn.style.fontWeight = '500';
                assignBtn.style.fontSize = '12.5px';
                assignBtn.innerHTML = '🚚 Assign Courier';
                assignBtn.addEventListener('click', () => {
                    openAssignCourierModal(order.id, order.courier_name, order.tracking_number);
                });
                rightActionWrap.appendChild(assignBtn);
            }

            // 3. Primary State Transitions
            if (actions.includes('dispatch')) {
                const dispatchBtn = document.createElement('button');
                dispatchBtn.type = 'button';
                dispatchBtn.className = 'btn btn-primary btn-sm';
                dispatchBtn.style.fontWeight = '600';
                dispatchBtn.style.fontSize = '12.5px';
                dispatchBtn.innerHTML = '🚚 Mark as Dispatched';
                dispatchBtn.addEventListener('click', () => updateOrderStatus(order.id, 'DISPATCHED'));
                rightActionWrap.appendChild(dispatchBtn);
            }

            if (actions.includes('deliver')) {
                const deliverBtn = document.createElement('button');
                deliverBtn.type = 'button';
                deliverBtn.className = 'btn btn-success btn-sm';
                deliverBtn.style.fontWeight = '600';
                deliverBtn.style.fontSize = '12.5px';
                deliverBtn.innerHTML = '✅ Mark Delivered';
                deliverBtn.addEventListener('click', () => {
                    deliverCourierInput.value = '';
                    deliverAmountInput.value = order.total_amount;
                    window.openModal(deliverModal);
                });
                rightActionWrap.appendChild(deliverBtn);
            }

            if (actions.includes('remit') && canRemitRole) {
                const remitBtn = document.createElement('button');
                remitBtn.type = 'button';
                remitBtn.className = 'btn btn-sm';
                remitBtn.style.background = '#047857';
                remitBtn.style.color = '#ffffff';
                remitBtn.style.fontWeight = '600';
                remitBtn.style.fontSize = '12.5px';
                remitBtn.innerHTML = '💰 Remit to Store';
                remitBtn.addEventListener('click', () => {
                    remitOrderNumDisplay.textContent = order.order_number;
                    remitAmountDisplay.textContent = formatCurrency(order.total_amount);
                    remitNotesInput.value = '';
                    window.openModal(remitModal);
                });
                rightActionWrap.appendChild(remitBtn);
            }

            drawerModalFooter.appendChild(rightActionWrap);

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
            window.showToast(res.message || 'Status updated successfully.', 'success');
            window.closeModal('orderDrawerModal');
            loadOrders(currentPage);
        } else {
            window.showToast(res?.message || 'Failed to update order status.', 'danger');
        }
    }

    // 4. Modal Confirmations
    // Delivery Confirm
    confirmDeliverBtn.addEventListener('click', async () => {
        if (!activeOrderData) return;
        const courier = deliverCourierInput.value.trim();
        const amount = parseFloat(deliverAmountInput.value) || 0;

        if (!courier) {
            window.showToast('Please input the courier / collector name.', 'warning');
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
        window.closeModal('deliverModal');
    });

    // Reason Confirm (Cancel or Return)
    confirmReasonBtn.addEventListener('click', async () => {
        if (!activeOrderData || !pendingAction) return;
        const reason = reasonTextInput.value.trim();

        if (!reason) {
            window.showToast('Please provide a reason.', 'warning');
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
        window.closeModal('reasonModal');
        pendingAction = null;
    });

    // Remit Confirm
    confirmRemitBtn.addEventListener('click', async () => {
        if (!activeOrderData) return;

        confirmRemitBtn.disabled = true;
        confirmRemitBtn.textContent = 'Remitting...';

        const notes = remitNotesInput.value.trim();
        await updateOrderStatus(activeOrderData.order.id, 'REMITTED', {
            notes: notes
        });

        confirmRemitBtn.disabled = false;
        confirmRemitBtn.textContent = 'Confirm Cash Received';
        window.closeModal('remitModal');
    });

    // Assign Courier Functions (Phase 6)
    window.openAssignCourierModal = function(orderId, courierName, trackingNumber) {
        if (!orderId) return;
        courierOrderId.value = orderId;
        courierNameInput.value = courierName || 'J&T Express Philippines';
        courierTrackingInput.value = trackingNumber || '';
        autoDispatchCheck.checked = true;
        window.openModal(assignCourierModal);
    };

    confirmAssignCourierBtn.addEventListener('click', async () => {
        const orderId = parseInt(courierOrderId.value, 10);
        const courierName = courierNameInput.value.trim();
        const trackingNumber = courierTrackingInput.value.trim();
        const autoDispatch = autoDispatchCheck.checked;

        if (!orderId) {
            window.showToast('Invalid order reference.', 'warning');
            return;
        }
        if (!courierName) {
            window.showToast('Please select or enter the courier name.', 'warning');
            courierNameInput.focus();
            return;
        }
        if (!trackingNumber) {
            window.showToast('Please provide a tracking / waybill number.', 'warning');
            courierTrackingInput.focus();
            return;
        }

        confirmAssignCourierBtn.disabled = true;
        confirmAssignCourierBtn.textContent = 'Assigning & Dispatching...';

        try {
            const res = await API.post('handler.php?action=assign_courier', {
                action: 'assign_courier',
                id: orderId,
                courier_name: courierName,
                tracking_number: trackingNumber,
                auto_dispatch: autoDispatch ? 1 : 0
            });

            if (res && res.success) {
                window.showToast(res.message || 'Courier waybill assigned successfully.', 'success');
                window.closeModal(assignCourierModal);
                if (activeOrderData && activeOrderData.order && activeOrderData.order.id === orderId) {
                    openOrderDrawer(orderId);
                }
                loadOrders(currentPage);
            } else {
                window.showToast(res?.message || 'Failed to assign courier waybill.', 'danger');
            }
        } catch (err) {
            console.error('Assign courier error:', err);
            window.showToast('Network error while assigning courier.', 'danger');
        } finally {
            confirmAssignCourierBtn.disabled = false;
            confirmAssignCourierBtn.textContent = 'Save Waybill & Dispatch';
        }
    });

    // Filter Controls
    applyFiltersBtn.addEventListener('click', () => loadOrders(1));
    resetFiltersBtn.addEventListener('click', () => {
        filterSearch.value = '';
        filterOrderStatus.value = '';
        filterPaymentStatus.value = '';
        if (typeof filterPaymentMode !== 'undefined' && filterPaymentMode) {
            filterPaymentMode.value = '';
        }
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
