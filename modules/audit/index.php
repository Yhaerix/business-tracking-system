<?php
/**
 * Audit Trail & Security Activity Log Viewer
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

$pageTitle = 'Audit Trail & System Activity';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_permission('audit_view');

$db = get_db();

// Pre-load operators for initial SSR user filter dropdown
$stmtUsers = $db->query("SELECT id, username, full_name FROM users ORDER BY full_name ASC");
$usersList = $stmtUsers->fetchAll();

// Pre-load KPI statistics for fast SSR rendering
$stmtAuditKpi = $db->query("
    SELECT 
        COUNT(*) AS total_logs,
        SUM(CASE WHEN DATE(created_at) = CURRENT_DATE THEN 1 ELSE 0 END) AS today_logs,
        SUM(CASE WHEN action IN ('CREATE', 'UPDATE', 'SOFT_DELETE', 'DELETE', 'RESTOCK', 'ADJUSTMENT') THEN 1 ELSE 0 END) AS mutation_logs,
        SUM(CASE WHEN action IN ('LOGIN', 'FAILED_LOGIN', 'LOGOUT', 'PASSWORD_RESET') THEN 1 ELSE 0 END) AS auth_logs
    FROM audit_logs
");
$auditKpis = $stmtAuditKpi->fetch() ?: ['total_logs' => 0, 'today_logs' => 0, 'mutation_logs' => 0, 'auth_logs' => 0];

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    /* Audit Trail Viewer Styling */
    #auditTable {
        width: 100%;
        table-layout: fixed;
        min-width: 860px;
    }

    #auditTable th {
        padding: 12px 18px;
        font-size: 11.5px;
        font-weight: 600;
        letter-spacing: 0.04em;
        color: #475569;
        background: #f8fafc;
        border-bottom: 1px solid var(--border-color);
    }

    #auditTable td {
        padding: 12px 18px;
        vertical-align: middle;
        border-bottom: 1px solid var(--border-color);
    }

    .badge-module {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 2px 8px;
        border-radius: var(--radius-sm);
        display: inline-block;
    }

    .actor-cell {
        display: flex;
        flex-direction: column;
        gap: 1px;
        line-height: 1.25;
    }

    .actor-name {
        font-weight: 600;
        color: var(--text-main);
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 4px;
        flex-wrap: wrap;
    }

    .actor-username {
        font-size: 11.5px;
        color: var(--text-muted);
    }

    /* Clean, Human-Friendly Audit Drawer Content */
    .audit-change-card {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        padding: 14px 18px;
        margin-bottom: 12px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    }

    .audit-change-header {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        margin-bottom: 8px;
    }

    .audit-change-title {
        font-size: 13.5px;
        font-weight: 700;
        color: var(--text-main);
    }

    .audit-change-attr {
        font-size: 11px;
        font-family: monospace;
        color: var(--text-muted);
    }

    .audit-change-flow {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        background: #f8fafc;
        border-radius: 8px;
        padding: 10px 14px;
    }

    .audit-val-old {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12.5px;
        font-weight: 600;
        word-break: break-all;
    }

    .audit-arrow {
        color: var(--text-muted);
        font-size: 16px;
        font-weight: bold;
    }

    .audit-val-new {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12.5px;
        font-weight: 700;
        word-break: break-all;
    }

    .raw-json-box {
        background: #0f172a;
        color: #f8fafc;
        padding: 12px 14px;
        border-radius: var(--radius-md);
        font-family: Consolas, Monaco, "Courier New", monospace;
        font-size: 11.5px;
        overflow-x: auto;
        max-height: 220px;
        white-space: pre-wrap;
        word-break: break-word;
    }
</style>

<!-- Page Header & Action Bar -->
<div class="page-header"
    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0;">Audit Trail &amp; System
            Activity</h1>
        <p style="font-size: 13.5px; color: var(--text-muted); margin: 4px 0 0 0;">
            Immutable, non-repudiable audit ledger tracking operator actions, mutations, logins, and field-level diffs.
        </p>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <button type="button" class="btn btn-outline" id="refreshAuditBtn">
            🔄 Refresh Logs
        </button>
    </div>
</div>

<!-- Stat KPI Cards Grid -->
<div class="stats-grid">
    <!-- Stat 1: Total Events Logged -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Total Audit Events</span>
            <div class="stat-icon icon-primary">📜</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiTotalLogs">
            <?= number_format((int) $auditKpis['total_logs']) ?>
        </div>
        <div class="stat-subtext">Cumulative historical events</div>
    </div>

    <!-- Stat 2: Today's Events -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Today's Activity</span>
            <div class="stat-icon icon-info">⏱️</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiTodayLogs" style="color: var(--color-info);">
            <?= number_format((int) $auditKpis['today_logs']) ?>
        </div>
        <div class="stat-subtext">Events logged today</div>
    </div>

    <!-- Stat 3: Data Mutations -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Record Mutations</span>
            <div class="stat-icon icon-success">✏️</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiMutationLogs" style="color: var(--color-success);">
            <?= number_format((int) $auditKpis['mutation_logs']) ?>
        </div>
        <div class="stat-subtext">Creates, Updates, Deletions</div>
    </div>

    <!-- Stat 4: Security Events -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Security &amp; Auth Events</span>
            <div class="stat-icon icon-warning">🛡️</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiAuthLogs" style="color: #b45309;">
            <?= number_format((int) $auditKpis['auth_logs']) ?>
        </div>
        <div class="stat-subtext">Logins, Lockouts, Resets</div>
    </div>
</div>

<!-- Multi-Axis Filter Toolbar -->
<div class="filter-bar">
    <div class="filter-group" style="min-width: 140px;">
        <input type="date" class="form-input" id="dateFromFilter" title="From Date">
    </div>

    <div class="filter-group" style="min-width: 140px;">
        <input type="date" class="form-input" id="dateToFilter" title="To Date">
    </div>

    <div class="filter-group" style="min-width: 140px;">
        <select class="form-select" id="moduleFilter">
            <option value="">All Modules</option>
            <option value="auth">auth</option>
            <option value="orders">orders</option>
            <option value="sales">sales</option>
            <option value="expenses">expenses</option>
            <option value="inventory">inventory</option>
            <option value="products">products</option>
            <option value="users">users</option>
            <option value="invoices">invoices</option>
        </select>
    </div>

    <div class="filter-group" style="min-width: 140px;">
        <select class="form-select" id="actionFilter">
            <option value="">All Actions</option>
            <option value="CREATE">CREATE</option>
            <option value="UPDATE">UPDATE</option>
            <option value="SOFT_DELETE">SOFT_DELETE</option>
            <option value="RESTOCK">RESTOCK</option>
            <option value="ADJUSTMENT">ADJUSTMENT</option>
            <option value="LOGIN">LOGIN</option>
            <option value="LOGOUT">LOGOUT</option>
            <option value="FAILED_LOGIN">FAILED_LOGIN</option>
            <option value="PASSWORD_RESET">PASSWORD_RESET</option>
        </select>
    </div>

    <div class="filter-group" style="min-width: 160px;">
        <select class="form-select" id="userFilter">
            <option value="">All Operators</option>
            <?php foreach ($usersList as $u): ?>
                <option value="<?= (int) $u['id'] ?>">
                    <?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>
                    (@<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group" style="flex: 2; min-width: 180px;">
        <input type="text" class="form-input" id="searchFilter" placeholder="🔍 Search record ID, actor, or details...">
    </div>

    <div class="filter-group-fixed" style="display: flex; gap: 8px;">
        <button type="button" class="btn btn-primary" id="applyFiltersBtn">
            Filter
        </button>
        <button type="button" class="btn btn-outline" id="resetFiltersBtn">
            Reset
        </button>
    </div>
</div>

<!-- Audit Trail Data Table Container -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive" style="border: none; border-radius: 0; box-shadow: none;">
        <table class="table" id="auditTable">
            <thead>
                <tr>
                    <th style="width: 18%; min-width: 165px; white-space: nowrap;">Timestamp</th>
                    <th style="width: 25%; min-width: 180px;">Operator (Actor)</th>
                    <th style="width: 13%; min-width: 105px; white-space: nowrap;">Module</th>
                    <th style="width: 20%; min-width: 155px; white-space: nowrap;">Action</th>
                    <th style="width: 11%; min-width: 95px; white-space: nowrap;">Record ID</th>
                    <th class="text-right" style="width: 13%; min-width: 110px; white-space: nowrap;">Inspection</th>
                </tr>
            </thead>
            <tbody id="auditTableBody">
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                        <div
                            style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;">
                        </div>
                        Loading audit records...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls Footer -->
    <div class="pagination-container" id="paginationContainer">
        <div id="paginationSummary">Showing 0 of 0 records</div>
        <div class="pagination-controls">
            <button type="button" class="btn btn-outline btn-sm" id="prevPageBtn" disabled>
                &laquo; Previous
            </button>
            <span style="font-size: 13px; font-weight: 500; color: var(--text-main); margin: 0 4px;"
                id="currentPageIndicator">Page 1 of 1</span>
            <button type="button" class="btn btn-outline btn-sm" id="nextPageBtn" disabled>
                Next &raquo;
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 9. MODAL: Audit Diff Inspector (modules/audit/index.php)                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="inspectDiffModal">
    <div class="modal-card">
        <div class="modal-header"
            style="padding: 20px 24px 18px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #ffffff;">
            <h3 class="modal-title"
                style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0; letter-spacing: -0.01em;">Audit Log
                Inspection</h3>
            <button type="button" class="modal-close-round" onclick="closeModal('inspectDiffModal')" aria-label="Close"
                title="Close">&times;</button>
        </div>
        <div class="modal-body" style="background: #f8fafc; padding: 22px 24px; overflow-y: auto;">
            <!-- Top Summary Card (Humanized action, operator, when, log entry) -->
            <div id="auditSummaryCard"
                style="background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <!-- Populated dynamically by inspectAuditLog() -->
            </div>

            <!-- Helpful Guidance Notice Banner -->
            <div
                style="background: #eff6ff; border: 1px solid #dbeafe; border-radius: 12px; padding: 12px 18px; margin: 16px 0; font-size: 13px; color: #1e40af; line-height: 1.45;">
                Only the fields that changed are shown. “Not set” means the field had no value before this change.
            </div>

            <!-- WHAT CHANGED Section Header -->
            <div
                style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; margin-bottom: 12px;">
                WHAT CHANGED
            </div>

            <!-- Field Comparison Cards Container -->
            <div id="diffCardsContainer">
                <!-- Dynamically rendered field comparison cards -->
            </div>

            <!-- Footnote description -->
            <div style="font-size: 12px; color: #64748b; margin: 16px 0 12px; line-height: 1.45;">
                In the live system the Before and After values come from the saved old_values and new_values of each log
                entry.
            </div>
        </div>
        <div class="modal-footer"
            style="padding: 14px 24px; background: #ffffff; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
            <button type="button" class="btn btn-outline" onclick="closeModal('inspectDiffModal')"
                style="border-radius: 10px; padding: 8px 24px; font-weight: 600; color: #334155; border-color: #cbd5e1; background: #ffffff;">Close</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Client-Side JavaScript Logic                                              -->
<!-- ========================================================================= -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Current pagination state
        let currentPage = 1;
        const limit = 25;
        let totalPages = 1;
        let totalRecords = 0;

        // Table & Filter DOM elements
        const auditTableBody = document.getElementById('auditTableBody');
        const dateFromFilter = document.getElementById('dateFromFilter');
        const dateToFilter = document.getElementById('dateToFilter');
        const moduleFilter = document.getElementById('moduleFilter');
        const actionFilter = document.getElementById('actionFilter');
        const userFilter = document.getElementById('userFilter');
        const searchFilter = document.getElementById('searchFilter');
        const applyFiltersBtn = document.getElementById('applyFiltersBtn');
        const resetFiltersBtn = document.getElementById('resetFiltersBtn');
        const refreshAuditBtn = document.getElementById('refreshAuditBtn');

        // Pagination DOM elements
        const paginationSummary = document.getElementById('paginationSummary');
        const prevPageBtn = document.getElementById('prevPageBtn');
        const nextPageBtn = document.getElementById('nextPageBtn');
        const currentPageIndicator = document.getElementById('currentPageIndicator');

        // Diff Modal DOM elements
        const inspectDiffModal = document.getElementById('inspectDiffModal');
        const auditSummaryCard = document.getElementById('auditSummaryCard');
        const diffCardsContainer = document.getElementById('diffCardsContainer');

        // Dictionary of Human-Friendly Field Labels
        const FIELD_LABELS = {
            // Stock & Inventory
            stock_qty: 'Stock quantity',
            current_stock: 'Stock quantity',
            quantity: 'Quantity',
            threshold: 'Low-stock threshold',
            movement_type: 'Movement type',
            quantity_change: 'Quantity change',
            stock_before: 'Stock before',
            stock_after: 'Stock after',
            note: 'Adjustment note',
            notes: 'Adjustment note',
            reason: 'Reason for adjustment',

            // Orders & COD
            order_number: 'Order Number',
            customer_name: 'Customer Name',
            customer_phone: 'Customer Phone',
            delivery_address: 'Delivery Address',
            delivery_notes: 'Delivery Notes',
            subtotal: 'Items Subtotal',
            discount_amount: 'Discount Amount',
            delivery_fee: 'Delivery Fee',
            total_amount: 'Total Amount (Due)',
            order_status: 'Order Status',
            payment_method: 'Payment Method',
            payment_status: 'COD Payment Status',
            dispatched_at: 'Dispatched Date/Time',
            delivered_at: 'Delivered Date/Time',
            cancelled_at: 'Cancelled Date/Time',
            cancellation_reason: 'Cancellation Reason',
            remitted_at: 'Remitted Date/Time',
            remitted_to_user_id: 'Remitted Receiver Staff ID',

            // Expenses
            title: 'Expense Title',
            category_id: 'Expense Category',
            category_name: 'Category Name',
            amount: 'Expense Amount',
            expense_date: 'Expense Date',
            receipt_path: 'Receipt Attachment',
            notes: 'Remarks & Notes',
            void_reason: 'Reason for Voiding',

            // Products & Stock
            name: 'Product Name',
            sku: 'SKU Code',
            cost_price: 'Cost Price',
            selling_price: 'Selling Price',
            quantity: 'Quantity',
            current_stock: 'Stock Count',
            threshold: 'Low-Stock Threshold',
            status: 'Status',
            movement_type: 'Movement Type',
            quantity_change: 'Quantity Change',
            stock_before: 'Stock Before',
            stock_after: 'Stock After',
            reason: 'Reason for Adjustment',

            // Users & Auth
            username: 'Username',
            full_name: 'Staff Full Name',
            role_id: 'Role ID',
            role_name: 'Role Name',
            email: 'Email Address',
            password: 'Password Hash',
            last_login: 'Last Login',

            // System
            id: 'Record ID',
            user_id: 'Operator User ID',
            created_at: 'Created Timestamp',
            updated_at: 'Updated Timestamp',
            deleted_at: 'Voided / Deleted Timestamp'
        };

        function getHumanFieldLabel(key) {
            if (FIELD_LABELS[key]) return FIELD_LABELS[key];
            return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        }

        // Helper: HTML Escaping
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Action Badge Generator
        function getActionBadge(action) {
            const act = (action || '').toUpperCase();
            let badgeClass = 'badge-outline';

            if (act === 'CREATE') {
                badgeClass = 'badge-primary';
            } else if (['UPDATE', 'RESTOCK', 'STATUS_REMITTED', 'REMITTED'].includes(act)) {
                badgeClass = 'badge-success';
            } else if (['ADJUSTMENT', 'FIX_COUNT', 'STOCK_OUT'].includes(act)) {
                badgeClass = 'badge-warning';
            } else if (['SOFT_DELETE', 'DELETE', 'CANCEL', 'FAILED_LOGIN'].includes(act)) {
                badgeClass = 'badge-danger';
            } else if (['LOGIN', 'LOGOUT', 'PASSWORD_RESET'].includes(act)) {
                badgeClass = 'badge-info';
            }

            return `<span class="badge ${badgeClass}">${escapeHtml(act)}</span>`;
        }

        // Clean value visualizer
        function formatHumanValue(val) {
            if (val === null || val === undefined) {
                return '<em style="color: var(--text-muted); font-size: 12px;">(empty)</em>';
            }
            if (typeof val === 'boolean') {
                return val ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-danger">No</span>';
            }
            const str = String(val).trim();
            const upper = str.toUpperCase();

            if (['DELIVERED', 'CONFIRMED', 'ACTIVE', 'REMITTED', 'PAID', 'RESTOCK'].includes(upper)) {
                return `<span class="badge badge-success">${escapeHtml(str)}</span>`;
            }
            if (['PENDING', 'PENDING_COLLECTION', 'DISPATCHED', 'ADJUSTMENT'].includes(upper)) {
                return `<span class="badge badge-warning">${escapeHtml(str)}</span>`;
            }
            if (['CANCELLED', 'VOID', 'FAILED', 'RETURNED', 'INACTIVE', 'SOFT_DELETE'].includes(upper)) {
                return `<span class="badge badge-danger">${escapeHtml(str)}</span>`;
            }

            // If amount
            if (/^\d+(\.\d{1,2})?$/.test(str) && Number(str) > 100) {
                return `<span style="font-weight: 700; color: var(--color-primary); font-family: monospace;">₱ ${Number(str).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>`;
            }

            return `<span style="font-weight: 600; color: var(--text-main); word-break: break-word;">${escapeHtml(str)}</span>`;
        }

        // Load Audit Logs from Backend
        async function loadAuditLogs(page = 1) {
            currentPage = page;

            const params = new URLSearchParams({
                action: 'list_audit_logs',
                page: currentPage,
                limit: limit,
                module: moduleFilter.value,
                action_name: actionFilter.value,
                user_id: userFilter.value,
                date_from: dateFromFilter.value,
                date_to: dateToFilter.value,
                search: searchFilter.value.trim()
            });

            auditTableBody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    <div style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
                    Loading audit trail events...
                </td>
            </tr>`;

            const res = await API.get('handler.php?' + params.toString());

            if (!res || !res.success || !res.data) {
                auditTableBody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px 20px; color: var(--color-danger);">
                        ${escapeHtml(res?.message || 'Failed to fetch audit logs. Please retry.')}
                    </td>
                </tr>`;
                return;
            }

            const { logs, pagination } = res.data;

            // Update Pagination state
            if (pagination) {
                totalPages = parseInt(pagination.total_pages, 10) || 1;
                totalRecords = parseInt(pagination.total_records, 10) || 0;
                currentPage = parseInt(pagination.current_page, 10) || 1;

                const start = totalRecords > 0 ? (currentPage - 1) * limit + 1 : 0;
                const end = Math.min(currentPage * limit, totalRecords);

                paginationSummary.textContent = `Showing ${start.toLocaleString()} - ${end.toLocaleString()} of ${totalRecords.toLocaleString()} events`;
                currentPageIndicator.textContent = `Page ${currentPage} of ${totalPages}`;

                prevPageBtn.disabled = (currentPage <= 1);
                nextPageBtn.disabled = (currentPage >= totalPages);
            }

            renderTable(logs || []);
        }

        // Render Table Rows
        function renderTable(logs) {
            if (!logs || logs.length === 0) {
                auditTableBody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                        No audit events match your selected filters.
                    </td>
                </tr>`;
                return;
            }

            auditTableBody.innerHTML = logs.map(log => {
                const logId = parseInt(log.id, 10);
                const actorName = log.full_name || 'System / Guest';
                const actorUser = log.username ? `@${log.username}` : (log.user_id ? `ID #${log.user_id}` : 'system');
                const roleBadge = log.role_name ? `<span class="badge badge-outline" style="font-size: 10px; padding: 1px 6px;">${escapeHtml(log.role_name)}</span>` : '';

                const recordIdText = log.record_id
                    ? `<span class="tabular-nums" style="font-weight: 600; color: var(--text-main);">#${parseInt(log.record_id, 10)}</span>`
                    : `<span style="color: var(--text-muted);">—</span>`;

                return `
                <tr>
                    <td class="tabular-nums" style="font-size: 12.5px; color: var(--text-muted); white-space: nowrap;">
                        ${escapeHtml(log.created_at)}
                    </td>
                    <td>
                        <div class="actor-cell">
                            <span class="actor-name">
                                ${escapeHtml(actorName)}
                                ${roleBadge}
                            </span>
                            <span class="actor-username">${escapeHtml(actorUser)}</span>
                        </div>
                    </td>
                    <td style="white-space: nowrap;">
                        <span class="badge-module">${escapeHtml(log.module)}</span>
                    </td>
                    <td style="white-space: nowrap;">
                        ${getActionBadge(log.action)}
                    </td>
                    <td style="white-space: nowrap; font-family: monospace;">
                        ${recordIdText}
                    </td>
                    <td class="text-right" style="white-space: nowrap;">
                        <button type="button" class="btn btn-outline btn-sm inspect-log-btn" data-id="${logId}" title="Inspect Event Details & Diff">
                            <svg viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; margin-right: 4px; vertical-align: -1px;"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>Inspect
                        </button>
                    </td>
                </tr>
            `;
            }).join('');
        }

        // -------------------------------------------------------------------------
        // Inspect Event & Diff Table Visualizer (new-design.html specification)
        // -------------------------------------------------------------------------
        function formatDiffCell(val, type) {
            if (val === null || val === undefined || val === '') {
                return '<span style="color: var(--text-muted);"><em>NULL</em></span>';
            }
            if (typeof val === 'boolean') {
                return val ? '<span class="badge badge-success">TRUE</span>' : '<span class="badge badge-danger">FALSE</span>';
            }
            const str = String(val).trim();
            const upper = str.toUpperCase();

            if (['CONFIRMED', 'DELIVERED', 'ACTIVE', 'REMITTED', 'PAID', 'RESTOCK'].includes(upper)) {
                return `<span class="badge badge-success">${escapeHtml(str)}</span>`;
            }
            if (['PENDING', 'PENDING COLLECTION', 'PENDING_COLLECTION', 'DISPATCHED', 'ADJUSTMENT'].includes(upper)) {
                return `<span class="badge badge-warning">${escapeHtml(str)}</span>`;
            }
            if (['CANCELLED', 'VOID', 'FAILED', 'RETURNED', 'INACTIVE', 'SOFT_DELETE'].includes(upper)) {
                return `<span class="badge badge-danger">${escapeHtml(str)}</span>`;
            }
            if (['CREATE'].includes(upper)) {
                return `<span class="badge badge-primary">${escapeHtml(str)}</span>`;
            }

            // Amount / currency format
            if (/^\d+(\.\d{1,2})?$/.test(str) && Number(str) > 50) {
                return `<span style="font-weight: 700; color: var(--color-primary);" class="tabular-nums">₱ ${Number(str).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>`;
            }

            if (type === 'old') {
                return `<span class="val-old">${escapeHtml(str)}</span>`;
            }
            return `<span class="val-new">${escapeHtml(str)}</span>`;
        }

        function getHumanActionInfo(log) {
            const act = (log.action || '').toUpperCase();
            const mod = (log.module || '').toLowerCase();

            let title = `${act} Performed`;
            let badgeText = act;
            let iconBg = '#eff6ff';
            let iconColor = '#2563eb';

            // Pen / edit SVG
            const penSvg = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>`;
            // Plus SVG
            const plusSvg = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`;
            // Trash / Void SVG
            const trashSvg = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>`;
            // Shield / Security SVG
            const shieldSvg = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>`;

            let iconSvg = penSvg;

            if (mod === 'inventory') {
                if (act.includes('ADJUST') || act === 'UPDATE') {
                    title = 'Stock adjusted';
                    badgeText = 'ADJUSTMENT';
                    iconSvg = penSvg;
                    iconBg = '#eff6ff';
                    iconColor = '#2563eb';
                } else {
                    title = 'Inventory updated';
                    badgeText = act;
                    iconSvg = penSvg;
                }
            } else if (mod === 'products') {
                if (act === 'INSERT' || act === 'CREATE') {
                    title = 'Product created';
                    badgeText = 'CREATED';
                    iconSvg = plusSvg;
                    iconBg = '#f0fdf4';
                    iconColor = '#16a34a';
                } else if (act === 'UPDATE') {
                    title = 'Product updated';
                    badgeText = 'UPDATE';
                    iconSvg = penSvg;
                    iconBg = '#eff6ff';
                    iconColor = '#2563eb';
                } else if (act === 'DELETE') {
                    title = 'Product deleted';
                    badgeText = 'DELETED';
                    iconSvg = trashSvg;
                    iconBg = '#fef2f2';
                    iconColor = '#dc2626';
                }
            } else if (mod === 'orders' || mod === 'sales') {
                if (act === 'INSERT' || act === 'CREATE') {
                    title = 'Order created';
                    badgeText = 'CREATED';
                    iconSvg = plusSvg;
                    iconBg = '#f0fdf4';
                    iconColor = '#16a34a';
                } else if (act.includes('STATUS')) {
                    title = 'Order status changed';
                    badgeText = 'STATUS';
                    iconSvg = penSvg;
                } else if (act.includes('REMIT')) {
                    title = 'COD payment remitted';
                    badgeText = 'REMITTANCE';
                    iconSvg = penSvg;
                    iconBg = '#f0fdf4';
                    iconColor = '#16a34a';
                } else {
                    title = 'Order updated';
                    badgeText = act;
                    iconSvg = penSvg;
                }
            } else if (mod === 'expenses') {
                if (act === 'INSERT' || act === 'CREATE') {
                    title = 'Expense recorded';
                    badgeText = 'CREATED';
                    iconSvg = plusSvg;
                    iconBg = '#f0fdf4';
                    iconColor = '#16a34a';
                } else if (act.includes('VOID') || act.includes('DELETE')) {
                    title = 'Expense voided';
                    badgeText = 'VOIDED';
                    iconSvg = trashSvg;
                    iconBg = '#fef2f2';
                    iconColor = '#dc2626';
                } else {
                    title = 'Expense updated';
                    badgeText = 'UPDATE';
                    iconSvg = penSvg;
                }
            } else if (mod === 'auth') {
                iconSvg = shieldSvg;
                if (act.includes('LOGIN')) {
                    title = 'User logged in';
                    badgeText = 'LOGIN';
                    iconBg = '#f0fdf4';
                    iconColor = '#16a34a';
                } else if (act.includes('LOGOUT')) {
                    title = 'User logged out';
                    badgeText = 'LOGOUT';
                    iconBg = '#f8fafc';
                    iconColor = '#64748b';
                } else {
                    title = 'Security authentication';
                    badgeText = act;
                }
            } else if (mod === 'users') {
                if (act === 'INSERT' || act === 'CREATE') {
                    title = 'User created';
                    badgeText = 'CREATED';
                    iconSvg = plusSvg;
                    iconBg = '#f0fdf4';
                    iconColor = '#16a34a';
                } else {
                    title = 'User details updated';
                    badgeText = 'UPDATE';
                    iconSvg = penSvg;
                }
            } else {
                title = `${mod.charAt(0).toUpperCase() + mod.slice(1)} modified`;
                badgeText = act;
                iconSvg = penSvg;
            }

            return { title, badgeText, iconSvg, iconBg, iconColor };
        }

        function formatInspectVal(val, key = '') {
            if (val === null || val === undefined || val === '') {
                return '<em style="color: #64748b; font-style: italic;">Not set</em>';
            }
            if (typeof val === 'boolean') {
                return `<span style="font-family: monospace; font-weight: 700;">${val ? 'true' : 'false'}</span>`;
            }
            if (typeof val === 'object') {
                return `<pre style="margin: 0; font-family: monospace; font-size: 11.5px; white-space: pre-wrap; word-break: break-all;">${escapeHtml(JSON.stringify(val, null, 2))}</pre>`;
            }
            const str = String(val);
            const k = (key || '').toLowerCase();
            if ((k.includes('price') || k.includes('amount') || k.includes('fee') || k.includes('subtotal') || k.includes('cost')) && !isNaN(Number(val)) && str.trim() !== '') {
                return `₱${Number(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            }
            return escapeHtml(str);
        }

        function renderChangeCard(key, oldVal, newVal) {
            const humanLabel = getHumanFieldLabel(key);
            const hasOld = (oldVal !== undefined && oldVal !== null && oldVal !== '');
            const hasNew = (newVal !== undefined && newVal !== null && newVal !== '');

            let badgeText = 'CHANGED';
            let badgeStyle = 'background: #fef3c7; color: #b45309; border: 1px solid #fde68a;';
            let beforeBg = '#fef2f2';
            let beforeLabelColor = '#991b1b';
            let beforeValStyle = 'color: #991b1b; font-weight: 700;';
            let afterBg = '#f0fdf4';
            let afterLabelColor = '#166534';
            let afterValStyle = 'color: #166534; font-weight: 700;';

            if (!hasOld && hasNew) {
                badgeText = 'NEW';
                badgeStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;';
                beforeBg = '#f1f5f9';
                beforeLabelColor = '#94a3b8';
                beforeValStyle = 'color: #64748b; font-style: italic;';
            } else if (hasOld && !hasNew) {
                badgeText = 'REMOVED';
                badgeStyle = 'background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;';
                afterBg = '#f1f5f9';
                afterLabelColor = '#94a3b8';
                afterValStyle = 'color: #64748b; font-style: italic;';
            }

            const beforeHtml = formatInspectVal(oldVal, key);
            const afterHtml = formatInspectVal(newVal, key);

            return `
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 18px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <div style="display: flex; align-items: baseline; gap: 8px;">
                        <strong style="font-size: 14.5px; font-weight: 700; color: #0f172a;">${escapeHtml(humanLabel)}</strong>
                        <code style="font-family: monospace; font-size: 12px; color: #64748b; background: #f8fafc; padding: 1px 6px; border-radius: 4px; border: 1px solid #f1f5f9;">${escapeHtml(key)}</code>
                    </div>
                    <span style="font-size: 11px; font-weight: 700; letter-spacing: 0.5px; padding: 3px 9px; border-radius: 6px; text-transform: uppercase; ${badgeStyle}">
                        ${badgeText}
                    </span>
                </div>
                <div style="display: flex; align-items: center; gap: 14px;">
                    <!-- BEFORE -->
                    <div style="flex: 1; border-radius: 10px; padding: 10px 14px; background: ${beforeBg}; min-width: 0;">
                        <div style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: ${beforeLabelColor}; margin-bottom: 4px;">
                            BEFORE
                        </div>
                        <div style="font-size: 14px; word-break: break-word; ${beforeValStyle}">
                            ${beforeHtml}
                        </div>
                    </div>
                    <!-- ARROW -->
                    <div style="color: #94a3b8; font-size: 18px; font-weight: 600; flex-shrink: 0;">&rarr;</div>
                    <!-- AFTER -->
                    <div style="flex: 1; border-radius: 10px; padding: 10px 14px; background: ${afterBg}; min-width: 0;">
                        <div style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: ${afterLabelColor}; margin-bottom: 4px;">
                            AFTER
                        </div>
                        <div style="font-size: 14px; word-break: break-word; ${afterValStyle}">
                            ${afterHtml}
                        </div>
                    </div>
                </div>
            </div>
        `;
        }

        async function inspectAuditLog(logId) {
            if (auditSummaryCard) {
                auditSummaryCard.innerHTML = `
                <div style="text-align: center; padding: 24px; color: var(--text-muted);">
                    <div style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
                    Loading inspection details...
                </div>
            `;
            }
            if (diffCardsContainer) {
                diffCardsContainer.innerHTML = '';
            }

            openModal(inspectDiffModal);

            const res = await API.get(`handler.php?action=get_audit_detail&id=${logId}`);

            if (!res || !res.success || !res.data || !res.data.log) {
                if (auditSummaryCard) {
                    auditSummaryCard.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: var(--color-danger);">
                        ${escapeHtml(res?.message || 'Failed to retrieve event inspection details.')}
                    </div>
                `;
                }
                return;
            }

            const log = res.data.log;
            const info = getHumanActionInfo(log);
            const recordStr = log.record_id ? `#${log.record_id}` : 'N/A';

            // Render Top Summary Card matching user reference design
            auditSummaryCard.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: ${info.iconBg}; display: flex; align-items: center; justify-content: center; color: ${info.iconColor}; flex-shrink: 0;">
                        ${info.iconSvg}
                    </div>
                    <div>
                        <h4 style="margin: 0 0 3px 0; font-size: 16.5px; font-weight: 700; color: #0f172a; letter-spacing: -0.01em;">${escapeHtml(info.title)}</h4>
                        <div style="font-size: 12.5px; color: #64748b;">
                            Module: ${escapeHtml(log.module)} &middot; Record ${escapeHtml(recordStr)}
                        </div>
                    </div>
                </div>
                <div style="font-size: 11px; font-weight: 700; color: #475569; letter-spacing: 0.6px; text-transform: uppercase; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; flex-shrink: 0;">
                    ${escapeHtml(info.badgeText)}
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1.2fr 1.5fr 0.8fr; gap: 16px; margin-top: 18px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
                <div>
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">WHEN</div>
                    <div style="font-weight: 600; color: #0f172a; font-size: 13.5px;">${escapeHtml(log.created_at || '—')}</div>
                </div>
                <div>
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">OPERATOR</div>
                    <div style="font-size: 13.5px;">
                        <strong style="color: #0f172a; font-weight: 600;">${escapeHtml(log.full_name || 'System')}</strong> 
                        <span style="color: #64748b; font-size: 12.5px;">@${escapeHtml(log.username || 'user')}</span>
                    </div>
                </div>
                <div>
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">LOG ENTRY</div>
                    <div style="font-weight: 700; color: #0f172a; font-size: 14px;">#${escapeHtml(log.id)}</div>
                </div>
            </div>
        `;

            // Parse JSON payloads safely
            let oldVal = null;
            let newVal = null;

            try {
                oldVal = (typeof log.old_values === 'string') ? JSON.parse(log.old_values) : log.old_values;
            } catch (e) {
                oldVal = log.old_values;
            }

            try {
                newVal = (typeof log.new_values === 'string') ? JSON.parse(log.new_values) : log.new_values;
            } catch (e) {
                newVal = log.new_values;
            }

            // Render changed cards
            renderDiffCards(log, oldVal, newVal);
        }
        window.inspectAuditLog = inspectAuditLog;

        function renderDiffCards(log, oldVal, newVal) {
            if (!diffCardsContainer) return;

            // CASE 1: Auth & Session Events (No payload modification)
            if (!oldVal && !newVal) {
                diffCardsContainer.innerHTML = `
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px 20px; text-align: center; color: #64748b; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                    <div style="font-size: 28px; margin-bottom: 8px;">🔐</div>
                    <div style="font-weight: 700; color: #0f172a; font-size: 15px; margin-bottom: 4px;">
                        ${escapeHtml(log.action.replace(/_/g, ' '))} Activity
                    </div>
                    <div style="font-size: 13px; max-width: 440px; margin: 0 auto; line-height: 1.45;">
                        Operator <strong>${escapeHtml(log.full_name || 'User')}</strong> performed a session authentication action (${escapeHtml(log.action)}). No database records were modified.
                    </div>
                </div>
            `;
                return;
            }

            // CASE 2: Record Creation (newVal exists, oldVal is null)
            if (!oldVal && newVal) {
                const keys = Object.keys(newVal);
                diffCardsContainer.innerHTML = keys.map(k => renderChangeCard(k, null, newVal[k])).join('');
                return;
            }

            // CASE 3: Record Deletion / Void (oldVal exists, newVal is null)
            if (oldVal && !newVal) {
                const keys = Object.keys(oldVal);
                diffCardsContainer.innerHTML = keys.map(k => renderChangeCard(k, oldVal[k], null)).join('');
                return;
            }

            // CASE 4: Field-by-Field Modification (Both oldVal and newVal exist)
            const allKeys = Array.from(new Set([...Object.keys(oldVal), ...Object.keys(newVal)]));
            const changedKeys = allKeys.filter(k => {
                const o = oldVal[k];
                const n = newVal[k];
                return (o === undefined && n !== undefined) || (o !== undefined && n === undefined) || (JSON.stringify(o) !== JSON.stringify(n));
            });

            if (changedKeys.length === 0) {
                diffCardsContainer.innerHTML = `
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; text-align: center; color: #64748b; font-size: 13.5px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                    Payload was submitted with unchanged attribute values.
                </div>
            `;
                return;
            }

            diffCardsContainer.innerHTML = changedKeys.map(k => renderChangeCard(k, oldVal[k], newVal[k])).join('');
        }

        // Attach click delegate on table for Inspect buttons
        auditTableBody.addEventListener('click', (e) => {
            const btn = e.target.closest('.inspect-log-btn');
            if (btn) {
                const logId = parseInt(btn.dataset.id, 10);
                inspectAuditLog(logId);
            }
        });

        // -------------------------------------------------------------------------
        // Pagination & Filter Events
        // -------------------------------------------------------------------------
        prevPageBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                loadAuditLogs(currentPage - 1);
            }
        });

        nextPageBtn.addEventListener('click', () => {
            if (currentPage < totalPages) {
                loadAuditLogs(currentPage + 1);
            }
        });

        applyFiltersBtn.addEventListener('click', () => {
            loadAuditLogs(1);
        });

        resetFiltersBtn.addEventListener('click', () => {
            dateFromFilter.value = '';
            dateToFilter.value = '';
            moduleFilter.value = '';
            actionFilter.value = '';
            userFilter.value = '';
            searchFilter.value = '';
            loadAuditLogs(1);
        });

        refreshAuditBtn.addEventListener('click', () => {
            loadAuditLogs(currentPage);
            API.toast('Refreshed audit log feed.', 'info');
        });

        // Initial load
        loadAuditLogs(1);
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>