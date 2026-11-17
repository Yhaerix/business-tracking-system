<?php
/**
 * Role-Aware Sidebar Navigation with Icon-Only Collapse Support
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/rbac.php';

if (!function_exists('is_active')) {
    function is_active(string $match, ?string $exclude = null): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $uri = str_replace('\\', '/', urldecode($_SERVER['REQUEST_URI'] ?? ''));
        $target = !empty($script) ? $script : (parse_url($uri, PHP_URL_PATH) ?? '');

        if ($exclude !== null && strpos($target, $exclude) !== false) {
            return '';
        }

        return (strpos($target, $match) !== false) ? 'active' : '';
    }
}

$sidebarStoreName = $activeStoreName ?? ($systemStoreName ?? ($_SESSION['store_name'] ?? (defined('APP_NAME') ? APP_NAME : 'Store Panel')));
?>
<aside class="app-sidebar" id="appSidebar">
    <div class="sidebar-header">
        <a href="<?= BASE_URL ?>/modules/dashboard/" class="sidebar-brand">
            <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="<?= htmlspecialchars($sidebarStoreName, ENT_QUOTES, 'UTF-8') ?> Logo" class="brand-logo"
                width="54" height="54">
            <span class="brand-text"><?= htmlspecialchars($sidebarStoreName, ENT_QUOTES, 'UTF-8') ?></span>
        </a>

        <!-- Button to collapse / expand the sidebar (Icon-Only Mode) placed beside logo/title -->
        <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" onclick="toggleSidebarCollapse()"
            aria-label="Toggle sidebar collapse" title="Collapse / expand sidebar">
            <svg viewBox="0 0 24 24">
                <path d="M15 5l-7 7 7 7" />
            </svg>
        </button>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Main</div>

        <a href="<?= BASE_URL ?>/modules/dashboard/" class="nav-link <?= is_active('/modules/dashboard/') ?>"
            <?= is_active('/modules/dashboard/') ? 'aria-current="page"' : '' ?> title="Dashboard">
            <svg class="nav-ico" viewBox="0 0 24 24">
                <rect x="3" y="3" width="7" height="9" rx="1.5" />
                <rect x="14" y="3" width="7" height="5" rx="1.5" />
                <rect x="14" y="12" width="7" height="9" rx="1.5" />
                <rect x="3" y="16" width="7" height="5" rx="1.5" />
            </svg>
            <span>Dashboard</span>
        </a>

        <a href="<?= BASE_URL ?>/store/?admin_session=1" class="nav-link" target="_blank" title="Open Public <?= htmlspecialchars($sidebarStoreName, ENT_QUOTES, 'UTF-8') ?> Storefront">
            <svg class="nav-ico" viewBox="0 0 24 24">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            <span>Public Storefront ↗</span>
        </a>

        <div class="nav-section-title">Sales & Orders</div>

        <a href="<?= BASE_URL ?>/modules/orders/"
            class="nav-link <?= is_active('/modules/orders/') ?>" <?= is_active('/modules/orders/') ? 'aria-current="page"' : '' ?> title="Orders & Delivery">
            <svg class="nav-ico" viewBox="0 0 24 24">
                <path d="M3 7l9-4 9 4-9 4-9-4z" />
                <path d="M3 7v10l9 4 9-4V7M12 11v10" />
            </svg>
            <span>Orders & Delivery</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/invoices/" class="nav-link <?= is_active('/modules/invoices/') ?>"
            <?= is_active('/modules/invoices/') ? 'aria-current="page"' : '' ?> title="Invoices & Receipts">
            <svg class="nav-ico" viewBox="0 0 24 24">
                <path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2z" />
                <path d="M9 7h6M9 11h6" />
            </svg>
            <span>Invoices & Receipts</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/customers/"
            class="nav-link <?= is_active('/modules/customers/') ?>" <?= is_active('/modules/customers/') ? 'aria-current="page"' : '' ?> title="User Management (Store Users)">
            <svg class="nav-ico" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
            </svg>
            <span>User Management</span>
        </a>

        <div class="nav-section-title">Inventory & Catalog</div>

        <a href="<?= BASE_URL ?>/modules/products/" class="nav-link <?= is_active('/modules/products/') ?>"
            <?= is_active('/modules/products/') ? 'aria-current="page"' : '' ?> title="Products">
            <svg class="nav-ico" viewBox="0 0 24 24">
                <path d="M20 12l-8 8-9-9V3h8l9 9z" />
                <circle cx="7.5" cy="7.5" r="1.2" />
            </svg>
            <span>Products</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/inventory/" class="nav-link <?= is_active('/modules/inventory/') ?>"
            <?= is_active('/modules/inventory/') ? 'aria-current="page"' : '' ?> title="Stock Ledger">
            <svg class="nav-ico" viewBox="0 0 24 24">
                <path d="M4 7h16M4 12h16M4 17h16" />
                <circle cx="7" cy="7" r=".6" />
                <circle cx="7" cy="12" r=".6" />
                <circle cx="7" cy="17" r=".6" />
            </svg>
            <span>Stock Ledger</span>
        </a>

        <!-- Expenses (Manager & Admin only) -->
        <?php if (has_permission('expenses_view')): ?>
            <div class="nav-section-title">Financials</div>
            <a href="<?= BASE_URL ?>/modules/expenses/" class="nav-link <?= is_active('/modules/expenses/') ?>"
                <?= is_active('/modules/expenses/') ? 'aria-current="page"' : '' ?> title="Expenses">
                <svg class="nav-ico" viewBox="0 0 24 24">
                    <rect x="2" y="6" width="20" height="13" rx="2" />
                    <circle cx="12" cy="12.5" r="3" />
                    <path d="M6 6V4h12v2" />
                </svg>
                <span>Expenses</span>
            </a>
        <?php endif; ?>

        <!-- Reports (Manager & Admin only) -->
        <?php if (has_permission('reports_view')): ?>
            <a href="<?= BASE_URL ?>/modules/reports/" class="nav-link <?= is_active('/modules/reports/') ?>"
                <?= is_active('/modules/reports/') ? 'aria-current="page"' : '' ?> title="Financial Reports">
                <svg class="nav-ico" viewBox="0 0 24 24">
                    <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
                </svg>
                <span>Financial Reports</span>
            </a>
        <?php endif; ?>

        <!-- Administration (Admin & Manager) -->
        <?php if (has_permission('users_manage') || has_permission('audit_view') || has_permission('settings_view')): ?>
            <div class="nav-section-title">System & Security</div>

            <?php if (has_permission('users_manage')): ?>
                <a href="<?= BASE_URL ?>/modules/users/" class="nav-link <?= is_active('/modules/users/') ?>"
                    <?= is_active('/modules/users/') ? 'aria-current="page"' : '' ?> title="Staff & Operators">
                    <svg class="nav-ico" viewBox="0 0 24 24">
                        <circle cx="9" cy="8" r="3.5" />
                        <path
                            d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6M17 4.5a3.5 3.5 0 010 7M21.5 20c0-2.6-1.4-4.6-3.5-5.4" />
                    </svg>
                    <span>Staff &amp; Operators</span>
                </a>
            <?php endif; ?>

            <?php if (has_permission('settings_view')): ?>
                <a href="<?= BASE_URL ?>/modules/settings/" class="nav-link <?= is_active('/modules/settings/') ?>"
                    <?= is_active('/modules/settings/') ? 'aria-current="page"' : '' ?> title="System Settings">
                    <svg class="nav-ico" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
                    </svg>
                    <span>System Settings</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-badge">
            <span
                class="user-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Staff', ENT_QUOTES, 'UTF-8') ?></span>
            <span class="user-role"><?= htmlspecialchars($_SESSION['role_label'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
</aside>
