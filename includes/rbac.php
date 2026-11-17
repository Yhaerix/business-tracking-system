<?php
/**
 * Role-Based Access Control (RBAC) Guard
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Master Permission Matrix by Role
 */
function get_role_permissions(): array {
    return [
        ROLE_ADMIN => [
            // Admin: Complete authority
            'dashboard_view', 'dashboard_financials',
            'products_view', 'products_manage',
            'inventory_view', 'inventory_adjust',
            'orders_view', 'orders_create', 'orders_update_status', 'orders_cancel', 'orders_remit_cod',
            'expenses_view', 'expenses_manage', 'expenses_delete',
            'invoices_view', 'invoices_manage',
            'reports_view', 'reports_net_income', 'reports_export',
            'audit_view',
            'users_manage',
            'settings_view', 'settings_manage'
        ],
        ROLE_MANAGER => [
            // Manager: Store operations, inventory adjustments, and expense entry
            'dashboard_view', 'dashboard_financials',
            'products_view', 'products_manage',
            'inventory_view', 'inventory_adjust',
            'orders_view', 'orders_create', 'orders_update_status', 'orders_cancel', 'orders_remit_cod',
            'expenses_view', 'expenses_manage',
            'invoices_view',
            'reports_view', 'reports_net_income', 'reports_export',
            'audit_view',
            'settings_view'
        ],
        ROLE_CASHIER => [
            // Cashier: Point of sale, COD order creation and dispatch, basic catalog view
            'dashboard_view',
            'products_view',
            'inventory_view',
            'orders_view', 'orders_create', 'orders_update_status', 'orders_cancel',
            'invoices_view'
        ]
    ];
}

/**
 * Check if the active session role has a specific privilege
 */
function has_permission(string $permission): bool {
    if (!is_logged_in()) {
        return false;
    }

    $role = $_SESSION['role_name'] ?? '';
    $matrix = get_role_permissions();

    if (!isset($matrix[$role])) {
        return false;
    }

    return in_array($permission, $matrix[$role], true);
}

/**
 * Enforce privilege check, blocking unauthorized access with 403 Forbidden
 */
function require_permission(string $permission): void {
    require_login();

    if (!has_permission($permission)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(403);
            echo json_encode([
                'success' => false, 
                'message' => 'Access denied: You do not possess the required privilege (' . $permission . ')',
                'data'    => null, 
                'errors'  => []
            ]);
            exit;
        }

        http_response_code(403);
        include __DIR__ . '/../modules/auth/403.php';
        exit;
    }
}
