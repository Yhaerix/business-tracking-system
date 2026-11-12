<?php
/**
 * Audit Trail & Security Activity Log Viewer
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

$pageTitle = 'Audit Trail';

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
}
.actor-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.actor-name {
    font-weight: 600;
    color: var(--text-main);
    font-size: 13.5px;
}
.actor-username {
    font-size: 11.5px;
    color: var(--text-muted);
}
.diff-modal-card {
    max-width: 780px !important;
}
.diff-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    background: #f8fafc;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 16px;
    margin-bottom: 20px;
}
.diff-meta-item {
    display: flex;
    flex-direction: column;
}
.diff-meta-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-muted);
    letter-spacing: 0.04em;
}
.diff-meta-value {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-main);
    margin-top: 2px;
    word-break: break-word;
}
.diff-table-container {
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    overflow: hidden;
    margin-top: 10px;
    margin-bottom: 16px;
}
.diff-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.diff-table th {
    background: #f1f5f9;
    padding: 10px 14px;
    font-weight: 600;
    color: #475569;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
    font-size: 11.5px;
    text-transform: uppercase;
}
.diff-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: top;
}
.diff-table tr:last-child td {
    border-bottom: none;
}
.val-old {
    background: #fee2e2;
    color: #991b1b;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 12px;
    display: inline-block;
    word-break: break-all;
}
.val-new {
    background: #dcfce7;
    color: #166534;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
    word-break: break-all;
}
.val-unchanged {
    color: var(--text-muted);
    font-family: monospace;
    font-size: 12px;
}
.raw-json-box {
    background: #0f172a;
    color: #f8fafc;
    padding: 14px;
    border-radius: var(--radius-md);
    font-family: Consolas, Monaco, "Courier New", monospace;
    font-size: 12px;
    overflow-x: auto;
    max-height: 250px;
    white-space: pre-wrap;
    word-break: break-word;
}
</style>

<!-- Page Header & Action Bar -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0;">Audit Trail & System Activity</h1>
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
            <?= number_format((int)$auditKpis['total_logs']) ?>
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
            <?= number_format((int)$auditKpis['today_logs']) ?>
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
            <?= number_format((int)$auditKpis['mutation_logs']) ?>
        </div>
        <div class="stat-subtext">Creates, Updates, Deletions</div>
    </div>

    <!-- Stat 4: Security Events -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Security & Auth Events</span>
            <div class="stat-icon icon-warning">🛡️</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiAuthLogs" style="color: #b45309;">
            <?= number_format((int)$auditKpis['auth_logs']) ?>
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

    <div class="filter-group" style="min-width: 150px;">
        <select class="form-select" id="userFilter">
            <option value="">All Operators</option>
            <?php foreach ($usersList as $u): ?>
                <option value="<?= (int)$u['id'] ?>">
                    <?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?> (@<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group" style="flex: 2; min-width: 180px;">
        <input type="text" class="form-input" id="searchFilter" placeholder="🔍 Search record ID, IP, or details...">
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

<!-- Audit Trail Data Table -->
<div class="table-responsive">
    <table class="table" id="auditTable">
        <thead>
            <tr>
                <th style="width: 165px;">Timestamp</th>
                <th>Operator (Actor)</th>
                <th>Module</th>
                <th>Action</th>
                <th>Record ID</th>
                <th>IP Address</th>
                <th class="text-right" style="width: 130px;">Inspection</th>
            </tr>
        </thead>
        <tbody id="auditTableBody">
            <tr>
                <td colspan="7" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    Loading audit records...
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Pagination Controls Footer -->
    <div class="pagination-container" id="paginationContainer">
        <div id="paginationSummary">Showing 0 of 0 records</div>
        <div class="pagination-controls">
            <button type="button" class="btn btn-outline btn-sm" id="prevPageBtn" disabled>
                &larr; Previous
            </button>
            <span style="font-weight: 600; padding: 0 8px;" id="currentPageIndicator">Page 1 of 1</span>
            <button type="button" class="btn btn-outline btn-sm" id="nextPageBtn" disabled>
                Next &rarr;
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Inspect Diff & Event Details                                       -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="inspectDiffModal" role="dialog" aria-modal="true" aria-labelledby="diffModalTitle">
    <div class="modal-card diff-modal-card">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="diffModalTitle">Event Details & Diff Inspector</h2>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;" id="diffModalSubtitle">
                    Audit Event #0
                </div>
            </div>
            <button type="button" class="modal-close" id="closeDiffModalBtn" aria-label="Close modal">&times;</button>
        </div>

        <div class="modal-body">
            <!-- Metadata Summary Grid -->
            <div class="diff-meta-grid" id="diffMetaGrid">
                <div class="diff-meta-item">
                    <span class="diff-meta-label">Timestamp</span>
                    <span class="diff-meta-value tabular-nums" id="diffMetaTime">—</span>
                </div>
                <div class="diff-meta-item">
                    <span class="diff-meta-label">Operator (Actor)</span>
                    <span class="diff-meta-value" id="diffMetaActor">—</span>
                </div>
                <div class="diff-meta-item">
                    <span class="diff-meta-label">Target Module / Action</span>
                    <span class="diff-meta-value" id="diffMetaModuleAction">—</span>
                </div>
                <div class="diff-meta-item">
                    <span class="diff-meta-label">Record ID</span>
                    <span class="diff-meta-value tabular-nums" id="diffMetaRecordId">—</span>
                </div>
                <div class="diff-meta-item">
                    <span class="diff-meta-label">IP Address</span>
                    <span class="diff-meta-value tabular-nums" id="diffMetaIp">—</span>
                </div>
                <div class="diff-meta-item" style="grid-column: 1 / -1;">
                    <span class="diff-meta-label">Client User-Agent</span>
                    <span class="diff-meta-value" style="font-size: 12px; font-weight: normal; color: var(--text-muted);" id="diffMetaUserAgent">—</span>
                </div>
            </div>

            <!-- Field-by-Field Diff View Container -->
            <div style="margin-bottom: 8px;">
                <h3 style="font-size: 14.5px; font-weight: 600; color: var(--text-main); margin: 0 0 4px 0;">
                    Payload Modification Comparison
                </h3>
                <p style="font-size: 12px; color: var(--text-muted); margin: 0 0 10px 0;">
                    Changes between previous state and committed new values.
                </p>
            </div>

            <div id="diffContentArea">
                <!-- Dynamically generated diff table or message -->
            </div>

            <!-- Collapsible Raw JSON Dump for Deep Compliance -->
            <details style="margin-top: 18px; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 10px 14px;">
                <summary style="font-size: 12.5px; font-weight: 600; color: var(--text-muted); cursor: pointer; user-select: none;">
                    🛠️ View Raw JSON Payloads (Old / New)
                </summary>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px;">
                    <div>
                        <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--color-danger); margin-bottom: 4px;">
                            old_values
                        </div>
                        <div class="raw-json-box" id="rawOldValues">null</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--color-success); margin-bottom: 4px;">
                            new_values
                        </div>
                        <div class="raw-json-box" id="rawNewValues">null</div>
                    </div>
                </div>
            </details>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="closeDiffBtn">Close</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Client-Side JavaScript Logic                                             -->
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
    const closeDiffModalBtn = document.getElementById('closeDiffModalBtn');
    const closeDiffBtn = document.getElementById('closeDiffBtn');
    const diffModalSubtitle = document.getElementById('diffModalSubtitle');
    const diffMetaTime = document.getElementById('diffMetaTime');
    const diffMetaActor = document.getElementById('diffMetaActor');
    const diffMetaModuleAction = document.getElementById('diffMetaModuleAction');
    const diffMetaRecordId = document.getElementById('diffMetaRecordId');
    const diffMetaIp = document.getElementById('diffMetaIp');
    const diffMetaUserAgent = document.getElementById('diffMetaUserAgent');
    const diffContentArea = document.getElementById('diffContentArea');
    const rawOldValues = document.getElementById('rawOldValues');
    const rawNewValues = document.getElementById('rawNewValues');

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

    // Modal Helpers
    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Action Badge Generator
    function getActionBadge(action) {
        const act = (action || '').toUpperCase();
        let badgeClass = 'badge-pending';

        if (['CREATE', 'RESTOCK'].includes(act)) {
            badgeClass = 'badge-delivered'; // Green
        } else if (['UPDATE', 'ADJUSTMENT'].includes(act)) {
            badgeClass = 'badge-confirmed'; // Blue
        } else if (['SOFT_DELETE', 'DELETE', 'CANCEL', 'FAILED_LOGIN'].includes(act)) {
            badgeClass = 'badge-danger';    // Red
        } else if (['LOGIN', 'LOGOUT', 'PASSWORD_RESET'].includes(act)) {
            badgeClass = 'badge-info';      // Sky Blue
        }

        return `<span class="badge ${badgeClass}">${escapeHtml(act)}</span>`;
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
                <td colspan="7" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    Loading audit trail events...
                </td>
            </tr>`;

        const res = await API.get('handler.php?' + params.toString());

        if (!res || !res.success || !res.data) {
            auditTableBody.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align: center; padding: 40px 20px; color: var(--color-danger);">
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
            currentPageIndicator.textContent = `Page ${currentPage} of ${totalPages}` - '';
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
                    <td colspan="7" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                        No audit events match your selected filters.
                    </td>
                </tr>`;
            return;
        }

        auditTableBody.innerHTML = logs.map(log => {
            const logId = parseInt(log.id, 10);
            const actorName = log.full_name || 'System / Guest';
            const actorUser = log.username ? `@${log.username}` : (log.user_id ? `ID #${log.user_id}` : 'system');
            const roleBadge = log.role_name ? `<span class="badge badge-confirmed" style="font-size: 10px; margin-left: 4px;">${escapeHtml(log.role_name)}</span>` : '';

            const recordIdText = log.record_id 
                ? `<span class="tabular-nums" style="font-weight: 600; color: var(--text-main);">#${parseInt(log.record_id, 10)}</span>`
                : `<span style="color: var(--text-muted);">—</span>`;

            return `
                <tr>
                    <td class="tabular-nums" style="font-size: 12.5px; color: var(--text-main);">
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
                    <td>
                        <span class="badge-module">${escapeHtml(log.module)}</span>
                    </td>
                    <td>${getActionBadge(log.action)}</td>
                    <td>${recordIdText}</td>
                    <td class="tabular-nums" style="font-size: 12px; color: var(--text-muted);">
                        ${escapeHtml(log.ip_address)}
                    </td>
                    <td class="text-right">
                        <button type="button" class="btn btn-outline btn-sm inspect-log-btn" data-id="${logId}">
                            🔍 Diff
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    // -------------------------------------------------------------------------
    // Inspect Event & Diff Visualizer
    // -------------------------------------------------------------------------
    async function inspectAuditLog(logId) {
        diffModalSubtitle.textContent = `Fetching audit event #${logId}...`;
        diffContentArea.innerHTML = `
            <div style="text-align: center; padding: 30px; color: var(--text-muted);">
                Analyzing log diff...
            </div>`;
        openModal(inspectDiffModal);

        const res = await API.get(`handler.php?action=get_audit_detail&id=${logId}`);

        if (!res || !res.success || !res.data || !res.data.log) {
            diffContentArea.innerHTML = `
                <div class="alert alert-danger">
                    ${escapeHtml(res?.message || 'Failed to retrieve event inspection details.')}
                </div>`;
            return;
        }

        const log = res.data.log;

        // Populate Metadata Card
        diffModalSubtitle.textContent = `Audit Event #${log.id} • ${escapeHtml(log.module)}.${escapeHtml(log.action)}`;
        diffMetaTime.textContent = log.created_at || '—';
        diffMetaActor.textContent = log.full_name ? `${log.full_name} (@${log.username || 'user'})` : 'System';
        diffMetaModuleAction.innerHTML = `<span class="badge-module">${escapeHtml(log.module)}</span> ${getActionBadge(log.action)}`;
        diffMetaRecordId.textContent = log.record_id ? `#${log.record_id}` : 'None';
        diffMetaIp.textContent = log.ip_address || '—';
        diffMetaUserAgent.textContent = log.user_agent || 'Unknown Client';

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

        // Dump Raw JSON tabs
        rawOldValues.textContent = oldVal ? JSON.stringify(oldVal, null, 2) : 'null';
        rawNewValues.textContent = newVal ? JSON.stringify(newVal, null, 2) : 'null';

        // Build Visual Diff View
        renderDiffVisualizer(oldVal, newVal);
    }

    function renderDiffVisualizer(oldVal, newVal) {
        // Case 1: Neither exists
        if (!oldVal && !newVal) {
            diffContentArea.innerHTML = `
                <div style="background: var(--bg-body); border-radius: var(--radius-md); padding: 24px; text-align: center; color: var(--text-muted); font-size: 13.5px;">
                    ℹ️ No data modification payloads were recorded for this security event.
                </div>`;
            return;
        }

        // Case 2: Creation event (newVal only)
        if (!oldVal && newVal) {
            const keys = Object.keys(newVal);
            diffContentArea.innerHTML = `
                <div style="margin-bottom: 8px;">
                    <span class="badge badge-delivered">Newly Created Record</span>
                </div>
                <div class="diff-table-container">
                    <table class="diff-table">
                        <thead>
                            <tr>
                                <th style="width: 35%;">Field Name</th>
                                <th>Assigned Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${keys.map(k => `
                                <tr>
                                    <td style="font-weight: 600; color: var(--text-main); font-family: monospace;">${escapeHtml(k)}</td>
                                    <td><span class="val-new">${escapeHtml(formatDiffValue(newVal[k]))}</span></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>`;
            return;
        }

        // Case 3: Deletion event (oldVal only)
        if (oldVal && !newVal) {
            const keys = Object.keys(oldVal);
            diffContentArea.innerHTML = `
                <div style="margin-bottom: 8px;">
                    <span class="badge badge-danger">Deleted Record State</span>
                </div>
                <div class="diff-table-container">
                    <table class="diff-table">
                        <thead>
                            <tr>
                                <th style="width: 35%;">Field Name</th>
                                <th>Previous Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${keys.map(k => `
                                <tr>
                                    <td style="font-weight: 600; color: var(--text-main); font-family: monospace;">${escapeHtml(k)}</td>
                                    <td><span class="val-old">${escapeHtml(formatDiffValue(oldVal[k]))}</span></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>`;
            return;
        }

        // Case 4: Field-by-field Comparison (Both old and new exist)
        const allKeys = Array.from(new Set([...Object.keys(oldVal), ...Object.keys(newVal)]));
        const changedRows = [];
        const unchangedRows = [];

        allKeys.forEach(k => {
            const o = oldVal[k];
            const n = newVal[k];
            const isChanged = JSON.stringify(o) !== JSON.stringify(n);

            if (isChanged) {
                changedRows.push(`
                    <tr style="background: #fffbeb;">
                        <td style="font-weight: 600; color: var(--text-main); font-family: monospace;">
                            ${escapeHtml(k)}
                            <span style="font-size: 10px; color: #b45309; margin-left: 4px; font-weight: bold;">(MODIFIED)</span>
                        </td>
                        <td><span class="val-old">${escapeHtml(formatDiffValue(o))}</span></td>
                        <td><span class="val-new">${escapeHtml(formatDiffValue(n))}</span></td>
                    </tr>
                `);
            } else {
                unchangedRows.push(`
                    <tr>
                        <td style="font-family: monospace; color: var(--text-muted);">${escapeHtml(k)}</td>
                        <td colspan="2"><span class="val-unchanged">${escapeHtml(formatDiffValue(n))}</span></td>
                    </tr>
                `);
            }
        });

        diffContentArea.innerHTML = `
            <div style="margin-bottom: 8px; font-size: 12.5px; color: var(--text-muted);">
                Found <strong>${changedRows.length}</strong> altered attribute(s):
            </div>
            <div class="diff-table-container">
                <table class="diff-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Attribute</th>
                            <th style="width: 35%;">Old Value (Before)</th>
                            <th style="width: 35%;">New Value (After)</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${changedRows.join('')}
                        ${unchangedRows.join('')}
                    </tbody>
                </table>
            </div>`;
    }

    function formatDiffValue(val) {
        if (val === null) return 'null';
        if (val === undefined) return 'undefined';
        if (typeof val === 'boolean') return val ? 'true' : 'false';
        if (typeof val === 'object') return JSON.stringify(val);
        return String(val);
    }

    // Attach click delegate on table for Inspect buttons
    auditTableBody.addEventListener('click', (e) => {
        const btn = e.target.closest('.inspect-log-btn');
        if (btn) {
            const logId = parseInt(btn.dataset.id, 10);
            inspectAuditLog(logId);
        }
    });

    closeDiffModalBtn.addEventListener('click', () => closeModal(inspectDiffModal));
    closeDiffBtn.addEventListener('click', () => closeModal(inspectDiffModal));

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

    // Close diff modal on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal(inspectDiffModal);
        }
    });

    // Close diff modal on backdrop click
    inspectDiffModal.addEventListener('click', (e) => {
        if (e.target === inspectDiffModal) {
            closeModal(inspectDiffModal);
        }
    });

    // Initial load
    loadAuditLogs(1);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

