<?php
/**
 * Role-Aware Sidebar Navigation
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
?>
<aside class="app-sidebar" id="appSidebar">
    <div class="sidebar-header">
        <a href="<?= BASE_URL ?>/modules/dashboard/" class="sidebar-brand">
            <span><?= APP_NAME ?></span>
        </a>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Main</div>

        <a href="<?= BASE_URL ?>/modules/dashboard/" class="nav-link <?= is_active('/modules/dashboard/') ?>"
            <?= is_active('/modules/dashboard/') ? 'aria-current="page"' : '' ?>>
            <span>Dashboard</span>
        </a>

        <div class="nav-section-title">Sales & Orders</div>

        <a href="<?= BASE_URL ?>/modules/orders/create.php"
            class="nav-link <?= is_active('/modules/orders/create.php') ?>" <?= is_active('/modules/orders/create.php') ? 'aria-current="page"' : '' ?>>
            <span>POS / New Order (COD)</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/orders/"
            class="nav-link <?= is_active('/modules/orders/', '/orders/create.php') ?>" <?= is_active('/modules/orders/', '/orders/create.php') ? 'aria-current="page"' : '' ?>>
            <span>Orders & Delivery</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/invoices/" class="nav-link <?= is_active('/modules/invoices/') ?>"
            <?= is_active('/modules/invoices/') ? 'aria-current="page"' : '' ?>>
            <span>Invoices & Receipts</span>
        </a>

        <div class="nav-section-title">Inventory & Catalog</div>

        <a href="<?= BASE_URL ?>/modules/products/" class="nav-link <?= is_active('/modules/products/') ?>"
            <?= is_active('/modules/products/') ? 'aria-current="page"' : '' ?>>
            <span>Products</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/inventory/" class="nav-link <?= is_active('/modules/inventory/') ?>"
            <?= is_active('/modules/inventory/') ? 'aria-current="page"' : '' ?>>
            <span>Stock Ledger</span>
        </a>

        <!-- Expenses (Manager & Admin only) -->
        <?php if (has_permission('expenses_view')): ?>
            <div class="nav-section-title">Financials</div>
            <a href="<?= BASE_URL ?>/modules/expenses/" class="nav-link <?= is_active('/modules/expenses/') ?>"
                <?= is_active('/modules/expenses/') ? 'aria-current="page"' : '' ?>>
                <span>Expenses</span>
            </a>
        <?php endif; ?>

        <!-- Reports (Manager & Admin only) -->
        <?php if (has_permission('reports_view')): ?>
            <a href="<?= BASE_URL ?>/modules/reports/" class="nav-link <?= is_active('/modules/reports/') ?>"
                <?= is_active('/modules/reports/') ? 'aria-current="page"' : '' ?>>
                <span>Financial Reports</span>
            </a>
        <?php endif; ?>

        <!-- Administration (Admin only) -->
        <?php if (has_permission('users_manage') || has_permission('audit_view')): ?>
            <div class="nav-section-title">System & Security</div>

            <?php if (has_permission('users_manage')): ?>
                <a href="<?= BASE_URL ?>/modules/users/" class="nav-link <?= is_active('/modules/users/') ?>"
                    <?= is_active('/modules/users/') ? 'aria-current="page"' : '' ?>>
                    <span>User Management</span>
                </a>
            <?php endif; ?>

            <?php if (has_permission('audit_view')): ?>
                <a href="<?= BASE_URL ?>/modules/audit/" class="nav-link <?= is_active('/modules/audit/') ?>"
                    <?= is_active('/modules/audit/') ? 'aria-current="page"' : '' ?>>
                    <span>Audit Logs</span>
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
