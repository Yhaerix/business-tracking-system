<?php
/**
 * Customer / Registered Users Administration View
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

$pageTitle = 'User Management';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
if (!has_permission('users_manage') && !has_permission('orders_view')) {
    header('Location: ' . BASE_URL . '/modules/dashboard/');
    exit;
}

$canManage = has_permission('users_manage');
$db = get_db();

// Pre-load KPI statistics for fast SSR render
$stmtKpi = $db->query("
    SELECT 
        COUNT(*) AS total_registered,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
        COALESCE(SUM(wallet_balance), 0.00) AS total_coins_held
    FROM customers
    WHERE deleted_at IS NULL
");
$stats = $stmtKpi->fetch() ?: [
    'total_registered' => 0,
    'active_count'     => 0,
    'inactive_count'   => 0,
    'total_coins_held' => 0.00
];

// Pre-load total orders placed by customers
$stmtOrderTotal = $db->query("
    SELECT COUNT(*) as total_cust_orders, COALESCE(SUM(total_amount), 0.00) as total_cust_sales
    FROM orders 
    WHERE customer_id IS NOT NULL AND deleted_at IS NULL
");
$orderStats = $stmtOrderTotal->fetch() ?: ['total_cust_orders' => 0, 'total_cust_sales' => 0.00];

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* Customer Management Custom UI Tokens */
.cust-avatar-badge {
    width: 38px;
    height: 38px;
    border-radius: var(--radius-full);
    background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
    color: #0369a1;
    font-weight: 700;
    font-size: 13.5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-transform: uppercase;
    flex-shrink: 0;
    border: 1px solid #7dd3fc;
}
.cust-name-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}
.cust-meta {
    line-height: 1.35;
}
.cust-full-name {
    font-weight: 600;
    font-size: 13.5px;
    color: var(--text-main);
}
.cust-email {
    font-size: 12px;
    color: var(--text-muted);
}
.badge-active {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.badge-inactive {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.clickable-row {
    cursor: pointer;
    transition: background 0.15s ease;
}
.clickable-row:hover {
    background: #f8fafc;
}
</style>

<!-- 4 Top KPI Stat Cards -->
<div class="stats-grid">
    <!-- Stat 1: Total Registered -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Total Registered</span>
            <div class="stat-icon icon-primary">👥</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiTotalRegistered">
            <?= number_format((int)$stats['total_registered']) ?>
        </div>
        <div class="stat-subtext">Storefront registered accounts</div>
    </div>

    <!-- Stat 2: Active Accounts -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Active Users</span>
            <div class="stat-icon icon-success">✅</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiActiveUsers" style="color: var(--color-success);">
            <?= number_format((int)$stats['active_count']) ?>
        </div>
        <div class="stat-subtext">Permitted to log in &amp; place orders</div>
    </div>

    <!-- Stat 3: Suspended / Inactive -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Suspended / Inactive</span>
            <div class="stat-icon icon-danger">⛔</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiInactiveUsers" style="color: var(--color-danger);">
            <?= number_format((int)$stats['inactive_count']) ?>
        </div>
        <div class="stat-subtext">Store access paused or blocked</div>
    </div>

    <!-- Stat 4: Customer Order Value -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Store Orders Placed</span>
            <div class="stat-icon icon-primary">🛍️</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiTotalOrders">
            <?= number_format((int)$orderStats['total_cust_orders']) ?>
        </div>
        <div class="stat-subtext">
            Total sales: <strong><?= APP_CURRENCY ?><?= number_format((float)$orderStats['total_cust_sales'], 2) ?></strong>
        </div>
    </div>
</div>

<!-- Filter Toolbar -->
<div class="filter-bar">
    <div class="filter-group" style="flex: 2; min-width: 240px;">
        <input type="text" class="form-input" id="searchFilter" placeholder="🔍 Search user by name, email, or phone...">
    </div>

    <div class="filter-group">
        <select class="form-select" id="statusFilter">
            <option value="all">All Statuses</option>
            <option value="active">Active Only</option>
            <option value="inactive">Inactive / Suspended</option>
        </select>
    </div>

    <div class="filter-group">
        <select class="form-select" id="sortFilter">
            <option value="created_at" data-dir="DESC">Newest Registered</option>
            <option value="created_at" data-dir="ASC">Oldest Registered</option>
            <option value="total_orders" data-dir="DESC">Most Orders Placed</option>
            <option value="wallet_balance" data-dir="DESC">Highest Coins Balance</option>
            <option value="name" data-dir="ASC">Name (A-Z)</option>
        </select>
    </div>

    <div class="filter-group-fixed">
        <button type="button" class="btn btn-outline" id="resetFiltersBtn" title="Reset all filters">
            Reset
        </button>
    </div>
</div>

<!-- Customer Table Container -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" id="customersTable">
            <thead>
                <tr>
                    <th style="width: 50px; text-align: center;">Avatar</th>
                    <th>User / Customer</th>
                    <th style="width: 140px;">Phone Number</th>
                    <th style="width: 130px; text-align: center;">Orders</th>
                    <th class="text-right" style="width: 140px; white-space: nowrap;">Coins Balance</th>
                    <th style="width: 130px;">Registered</th>
                    <th style="width: 140px; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="customerTableBody">
                <tr>
                    <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        <div style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
                        Loading registered users...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <div class="pagination-container" id="paginationBar">
        <div id="paginationInfo">Showing 0 of 0 users</div>
        <div class="pagination-controls">
            <button type="button" class="btn btn-outline btn-sm" id="prevPageBtn" disabled>&larr; Previous</button>
            <span id="pageIndicator" style="font-weight: 600; padding: 0 8px;">Page 1</span>
            <button type="button" class="btn btn-outline btn-sm" id="nextPageBtn" disabled>Next &rarr;</button>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- SLIDE-OUT DRAWER PANEL: Customer Details (#customerDrawerModal) -->
<!-- ============================================================== -->
<div class="modal-overlay" id="customerDrawerModal" role="dialog" aria-modal="true" aria-labelledby="drawerTitle">
    <div class="modal-card">
        <div class="modal-header" style="border-bottom: 1px solid var(--border-color); padding: 18px 24px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="cust-avatar-badge" id="drawerAvatar" style="width: 44px; height: 44px; font-size: 16px;">👤</div>
                <div>
                    <h3 class="modal-title" id="drawerTitle" style="font-size: 17px; margin: 0;">Customer Profile</h3>
                    <div id="drawerSubtitle" style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">User Account</div>
                </div>
            </div>
            <button type="button" class="modal-close" id="closeDrawerBtn" aria-label="Close Profile">&times;</button>
        </div>

        <div class="modal-body" id="drawerBody" style="padding: 24px; overflow-y: auto;">
            <!-- Loaded dynamically via JS -->
            <div style="text-align: center; padding: 30px; color: var(--text-muted);">
                Loading details...
            </div>
        </div>

        <div class="modal-footer" id="drawerFooter" style="border-top: 1px solid var(--border-color); padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; background: #fafbfc;">
            <!-- Injected dynamically based on permissions -->
            <button type="button" class="btn btn-outline" id="drawerCloseFooterBtn">Close</button>
            <div id="drawerActionSlot"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const canManage = <?= $canManage ? 'true' : 'false' ?>;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // DOM Elements
    const customersTable = document.getElementById('customersTable');
    const customerTableBody = document.getElementById('customerTableBody');
    const searchFilter = document.getElementById('searchFilter');
    const statusFilter = document.getElementById('statusFilter');
    const sortFilter = document.getElementById('sortFilter');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');
    const paginationInfo = document.getElementById('paginationInfo');
    const pageIndicator = document.getElementById('pageIndicator');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');

    // KPI Elements
    const kpiTotalRegistered = document.getElementById('kpiTotalRegistered');
    const kpiActiveUsers = document.getElementById('kpiActiveUsers');
    const kpiInactiveUsers = document.getElementById('kpiInactiveUsers');

    // Drawer Elements
    const customerDrawerModal = document.getElementById('customerDrawerModal');
    const closeDrawerBtn = document.getElementById('closeDrawerBtn');
    const drawerCloseFooterBtn = document.getElementById('drawerCloseFooterBtn');
    const drawerTitle = document.getElementById('drawerTitle');
    const drawerSubtitle = document.getElementById('drawerSubtitle');
    const drawerAvatar = document.getElementById('drawerAvatar');
    const drawerBody = document.getElementById('drawerBody');
    const drawerActionSlot = document.getElementById('drawerActionSlot');

    let currentPage = 1;
    let totalPages = 1;
    let searchDebounce = null;
    let currentDrawerCustomerId = 0;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatMoney(amount) {
        const n = parseFloat(amount) || 0;
        return '₱\u00A0' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function getInitials(name) {
        if (!name) return 'U';
        const parts = name.trim().split(/\s+/);
        if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('active');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    // -------------------------------------------------------------
    // Load Customer List from Backend
    // -------------------------------------------------------------
    async function loadCustomers(page = 1) {
        currentPage = page;
        customerTableBody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <div style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
                    Loading registered users...
                </td>
            </tr>`;

        const selectedSortOpt = sortFilter.options[sortFilter.selectedIndex];
        const sortBy = sortFilter.value;
        const sortDir = selectedSortOpt.getAttribute('data-dir') || 'DESC';

        const params = new URLSearchParams({
            action: 'list_customers',
            page: currentPage,
            limit: 12,
            search: searchFilter.value.trim(),
            status: statusFilter.value,
            sort_by: sortBy,
            sort_dir: sortDir
        });

        const res = await API.get(`handler.php?${params.toString()}`);
        if (!res || !res.success || !res.data) {
            customerTableBody.innerHTML = `
                <tr>
                    <td colspan="8" style="text-align: center; padding: 40px; color: var(--color-danger);">
                        Failed to load registered users. Please retry.
                    </td>
                </tr>`;
            return;
        }

        const { customers, pagination, stats: serverStats } = res.data;
        totalPages = pagination.total_pages || 1;

        // Update KPI metrics
        if (serverStats) {
            if (kpiTotalRegistered) kpiTotalRegistered.textContent = Number(serverStats.total_registered).toLocaleString();
            if (kpiActiveUsers) kpiActiveUsers.textContent = Number(serverStats.active_count).toLocaleString();
            if (kpiInactiveUsers) kpiInactiveUsers.textContent = Number(serverStats.inactive_count).toLocaleString();
        }

        if (!customers || customers.length === 0) {
            customerTableBody.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align: center; padding: 48px 20px; color: var(--text-muted);">
                        <div style="font-size: 36px; margin-bottom: 8px;">👥</div>
                        <div style="font-weight: 600; font-size: 15px; color: var(--text-main);">No Registered Users Found</div>
                        <p style="font-size: 13px; margin: 4px 0 0 0;">No accounts match your selected search or filter criteria.</p>
                    </td>
                </tr>`;
        } else {
            let html = '';
            customers.forEach(c => {
                const initials = getInitials(c.name);
                const orderCount = parseInt(c.total_orders, 10) || 0;
                const totalSpent = parseFloat(c.total_spent) || 0.00;
                const coins = parseFloat(c.wallet_balance) || 0.00;
                const regDate = c.created_at ? new Date(c.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';
                const isActive = c.status === 'active';
                const inactiveTag = !isActive 
                    ? '<span class="badge badge-inactive" style="font-size: 10px; padding: 1px 5px; margin-left: 6px;">Suspended</span>' 
                    : '';

                html += `
                    <tr class="clickable-row" data-id="${c.id}" title="Click row to open details drawer">
                        <td style="text-align: center; vertical-align: middle;">
                            <div class="cust-avatar-badge">${escapeHtml(initials)}</div>
                        </td>
                        <td>
                            <div class="cust-meta">
                                <div class="cust-full-name">
                                    <span>${escapeHtml(c.name)}</span>${inactiveTag}
                                </div>
                                <div class="cust-email">${escapeHtml(c.email || 'No email provided')}</div>
                            </div>
                        </td>
                        <td class="tabular-nums" style="font-family: monospace; font-size: 12.5px; color: #334155;">
                            ${escapeHtml(c.phone || '—')}
                        </td>
                        <td style="text-align: center;">
                            <span class="badge badge-outline" style="font-weight: 600;">${orderCount} orders</span>
                        </td>
                        <td class="text-right tabular-nums" style="font-weight: 600; color: #0f172a; white-space: nowrap;">
                            ${formatMoney(coins)}
                        </td>
                        <td style="font-size: 12px; color: var(--text-muted); white-space: nowrap;">
                            ${regDate}
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                <button type="button" class="btn btn-outline btn-sm view-cust-btn" data-id="${c.id}" title="View Details in Drawer">
                                    <svg viewBox="0 0 24 24" width="13" height="13" style="stroke:currentColor;fill:none;stroke-width:2;margin-right:4px;vertical-align:-1px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View
                                </button>
                                ${canManage ? `
                                    <button type="button" class="btn btn-outline btn-sm toggle-cust-btn" data-id="${c.id}" data-status="${c.status}" title="${isActive ? 'Suspend User' : 'Activate User'}" style="${!isActive ? 'color: var(--color-success); border-color: #a7f3d0;' : 'color: var(--color-danger); border-color: #fca5a5;'}">
                                        ${isActive ? '⛔ Suspend' : '✅ Activate'}
                                    </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            });
            customerTableBody.innerHTML = html;
        }

        // Update Pagination Controls
        paginationInfo.textContent = `Showing ${(pagination.total > 0 ? (pagination.page - 1) * pagination.limit + 1 : 0)} to ${Math.min(pagination.page * pagination.limit, pagination.total)} of ${pagination.total} users`;
        pageIndicator.textContent = `Page ${pagination.page} of ${Math.max(1, pagination.total_pages)}`;
        prevPageBtn.disabled = pagination.page <= 1;
        nextPageBtn.disabled = pagination.page >= pagination.total_pages;
    }

    // -------------------------------------------------------------
    // Open Customer Details Drawer
    // -------------------------------------------------------------
    async function openCustomerDrawer(id) {
        currentDrawerCustomerId = id;
        drawerBody.innerHTML = `
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <div style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
                Loading customer profile...
            </div>`;
        drawerActionSlot.innerHTML = '';
        openModal(customerDrawerModal);

        const res = await API.get(`handler.php?action=get_customer&id=${id}`);
        if (!res || !res.success || !res.data) {
            drawerBody.innerHTML = `<div class="alert alert-danger">${res ? res.message : 'Failed to load details.'}</div>`;
            return;
        }

        const { customer, addresses, orders, wallet_transactions } = res.data;
        const initials = getInitials(customer.name);
        const isActive = customer.status === 'active';

        drawerAvatar.textContent = initials;
        drawerTitle.textContent = customer.name;
        drawerSubtitle.textContent = customer.email || 'Store Customer';

        // Order history HTML
        let ordersHtml = '';
        if (!orders || orders.length === 0) {
            ordersHtml = `
                <div style="text-align: center; padding: 18px; color: var(--text-muted); font-size: 13px; background: #f8fafc; border-radius: var(--radius-md);">
                    No orders placed yet.
                </div>`;
        } else {
            ordersHtml = '<div style="display: flex; flex-direction: column; gap: 8px;">';
            orders.forEach(o => {
                const oDate = o.created_at ? new Date(o.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
                ordersHtml += `
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: #ffffff;">
                        <div>
                            <div style="font-weight: 600; font-size: 13.5px; font-family: monospace; color: var(--color-primary);">
                                #${escapeHtml(o.order_number)}
                            </div>
                            <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
                                ${oDate} &bull; ${escapeHtml(o.order_status)}
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div class="tabular-nums" style="font-weight: 600; font-size: 13.5px; color: var(--text-main);">
                                ${formatMoney(o.total_amount)}
                            </div>
                            <a href="<?= BASE_URL ?>/modules/orders/?search=${encodeURIComponent(o.order_number)}" class="btn btn-outline btn-sm" style="padding: 2px 8px; font-size: 11px; margin-top: 4px;">
                                View Order &rarr;
                            </a>
                        </div>
                    </div>
                `;
            });
            ordersHtml += '</div>';
        }

        // Addresses HTML
        let addressHtml = '';
        if (!addresses || addresses.length === 0) {
            addressHtml = '<div style="font-size: 13px; color: var(--text-muted);">No shipping addresses saved.</div>';
        } else {
            addressHtml = '<div style="display: flex; flex-direction: column; gap: 8px;">';
            addresses.forEach(a => {
                const fullAddr = [a.address_line1, a.address_line2, a.city, a.province, a.postal_code].filter(Boolean).join(', ');
                addressHtml += `
                    <div style="padding: 10px 12px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); font-size: 12.5px; line-height: 1.4;">
                        ${a.is_default ? '<span class="badge badge-primary" style="font-size: 10px; margin-bottom: 4px; display: inline-block;">Default</span>' : ''}
                        <div>${escapeHtml(fullAddr)}</div>
                    </div>
                `;
            });
            addressHtml += '</div>';
        }

        const regDateStr = customer.created_at ? new Date(customer.created_at).toLocaleString() : 'N/A';
        const lastLoginStr = customer.last_login_at ? new Date(customer.last_login_at).toLocaleString() : 'Never logged in';

        drawerBody.innerHTML = `
            <!-- Overview Stat Cards -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 12px 14px;">
                    <span style="font-size: 11.5px; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Apple Coins Balance</span>
                    <div class="tabular-nums" style="font-size: 18px; font-weight: 700; color: #0369a1; margin-top: 4px;">
                        ${formatMoney(customer.wallet_balance)}
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 12px 14px;">
                    <span style="font-size: 11.5px; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Lifetime Orders</span>
                    <div class="tabular-nums" style="font-size: 18px; font-weight: 700; color: var(--text-main); margin-top: 4px;">
                        ${customer.total_orders} <small style="font-size: 12px; font-weight: 400; color: var(--text-muted);">(${formatMoney(customer.total_spent)})</small>
                    </div>
                </div>
            </div>

            <!-- Profile Info Block -->
            <div style="margin-bottom: 22px;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-primary); margin-bottom: 10px;">
                    📋 Account Information
                </div>
                <div style="border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 14px 16px; background: #ffffff;">
                    <div style="display: grid; grid-template-columns: 120px 1fr; gap: 10px; font-size: 13px; line-height: 1.5;">
                        <span style="color: var(--text-muted);">Full Name:</span>
                        <span style="font-weight: 600; color: var(--text-main);">${escapeHtml(customer.name)}</span>

                        <span style="color: var(--text-muted);">Email:</span>
                        <span>${escapeHtml(customer.email || '—')}</span>

                        <span style="color: var(--text-muted);">Phone:</span>
                        <span class="tabular-nums" style="font-family: monospace;">${escapeHtml(customer.phone || '—')}</span>

                        <span style="color: var(--text-muted);">Status:</span>
                        <div>${isActive ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-inactive">Suspended</span>'}</div>

                        <span style="color: var(--text-muted);">Registered:</span>
                        <span style="font-size: 12px;">${regDateStr}</span>

                        <span style="color: var(--text-muted);">Last Login:</span>
                        <span style="font-size: 12px;">${lastLoginStr}</span>
                    </div>
                </div>
            </div>

            <!-- Addresses Block -->
            <div style="margin-bottom: 22px;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-primary); margin-bottom: 10px;">
                    📍 Delivery Addresses
                </div>
                ${addressHtml}
            </div>

            <!-- Order History Block -->
            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-primary);">
                        🛍️ Recent Orders (${orders.length})
                    </span>
                </div>
                ${ordersHtml}
            </div>

            <!-- Internal Admin Notes -->
            ${canManage ? `
                <div style="margin-bottom: 10px;">
                    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-primary); margin-bottom: 8px;">
                        📝 Staff Internal Notes
                    </div>
                    <textarea class="form-input" id="custNotesField" rows="3" placeholder="Enter notes or remarks regarding this customer account...">${escapeHtml(customer.notes || '')}</textarea>
                    <div style="margin-top: 8px; display: flex; justify-content: flex-end;">
                        <button type="button" class="btn btn-outline btn-sm" id="saveCustNotesBtn">Save Notes</button>
                    </div>
                </div>
            ` : ''}
        `;

        // Drawer Actions
        if (canManage) {
            drawerActionSlot.innerHTML = `
                <button type="button" class="btn ${isActive ? 'btn-danger' : 'btn-primary'}" id="drawerToggleStatusBtn">
                    ${isActive ? '⛔ Suspend Account' : '✅ Activate Account'}
                </button>
            `;

            document.getElementById('drawerToggleStatusBtn').addEventListener('click', () => {
                toggleCustomerStatus(customer.id, isActive ? 'inactive' : 'active');
            });

            const saveNotesBtn = document.getElementById('saveCustNotesBtn');
            if (saveNotesBtn) {
                saveNotesBtn.addEventListener('click', async () => {
                    const notes = document.getElementById('custNotesField').value.trim();
                    saveNotesBtn.disabled = true;
                    saveNotesBtn.textContent = 'Saving...';
                    const postRes = await API.post('handler.php', {
                        action: 'update_notes',
                        id: customer.id,
                        notes: notes,
                        csrf_token: csrfToken
                    });
                    saveNotesBtn.disabled = false;
                    saveNotesBtn.textContent = 'Save Notes';
                    if (postRes && postRes.success) {
                        API.toast('Customer notes updated.', 'success');
                    } else {
                        alert(postRes ? postRes.message : 'Failed to update notes.');
                    }
                });
            }
        }
    }

    // -------------------------------------------------------------
    // Toggle Active / Inactive Status
    // -------------------------------------------------------------
    async function toggleCustomerStatus(id, newStatus) {
        const actionLabel = newStatus === 'active' ? 'activate' : 'suspend';
        if (!confirm(`Are you sure you want to ${actionLabel} this customer account?`)) {
            return;
        }

        const res = await API.post('handler.php', {
            action: 'toggle_status',
            id: id,
            status: newStatus,
            csrf_token: csrfToken
        });

        if (res && res.success) {
            API.toast(res.message, 'success');
            loadCustomers(currentPage);
            if (currentDrawerCustomerId === id) {
                openCustomerDrawer(id);
            }
        } else {
            alert(res ? res.message : 'Failed to update account status.');
        }
    }

    // -------------------------------------------------------------
    // Delegated Table Click Listener
    // -------------------------------------------------------------
    customersTable.addEventListener('click', (e) => {
        // 1. Toggle Status Button
        const toggleBtn = e.target.closest('.toggle-cust-btn');
        if (toggleBtn) {
            e.stopPropagation();
            const id = parseInt(toggleBtn.dataset.id, 10);
            const currentStatus = toggleBtn.dataset.status;
            toggleCustomerStatus(id, currentStatus === 'active' ? 'inactive' : 'active');
            return;
        }

        // 2. View in Drawer Button
        const viewBtn = e.target.closest('.view-cust-btn');
        if (viewBtn) {
            e.stopPropagation();
            const id = parseInt(viewBtn.dataset.id, 10);
            openCustomerDrawer(id);
            return;
        }

        // 3. Row Click
        const row = e.target.closest('.clickable-row');
        if (row && !e.target.closest('button') && !e.target.closest('a')) {
            const id = parseInt(row.dataset.id, 10);
            openCustomerDrawer(id);
        }
    });

    // -------------------------------------------------------------
    // Event Listeners for Filters
    // -------------------------------------------------------------
    searchFilter.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => loadCustomers(1), 300);
    });

    statusFilter.addEventListener('change', () => loadCustomers(1));
    sortFilter.addEventListener('change', () => loadCustomers(1));

    resetFiltersBtn.addEventListener('click', () => {
        searchFilter.value = '';
        statusFilter.value = 'all';
        sortFilter.selectedIndex = 0;
        loadCustomers(1);
    });

    prevPageBtn.addEventListener('click', () => {
        if (currentPage > 1) loadCustomers(currentPage - 1);
    });

    nextPageBtn.addEventListener('click', () => {
        if (currentPage < totalPages) loadCustomers(currentPage + 1);
    });

    closeDrawerBtn.addEventListener('click', () => closeModal(customerDrawerModal));
    drawerCloseFooterBtn.addEventListener('click', () => closeModal(customerDrawerModal));

    // Backdrop Click & ESC Key
    customerDrawerModal.addEventListener('click', (e) => {
        if (e.target === customerDrawerModal) closeModal(customerDrawerModal);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeModal(customerDrawerModal);
    });

    // Initial Load
    loadCustomers(1);
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
