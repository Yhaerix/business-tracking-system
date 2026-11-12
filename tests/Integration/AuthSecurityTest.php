<?php
/**
 * Authentication & Security Integration Test
 * Verifies Phase 1 security invariants: CSRF, Hashing, and RBAC matrix
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/rbac.php';

class AuthSecurityTest {
    /**
     * Test CSRF Token Generation & Timing-Safe Verification
     */
    public function test_csrf_token_validation(): void {
        $token = get_csrf_token();
        assert_true(!empty($token), 'CSRF token should not be empty');
        assert_true(verify_csrf_token($token), 'Valid CSRF token must verify successfully');
        assert_false(verify_csrf_token('invalid_forged_token_12345'), 'Forged CSRF token must be rejected');
        assert_false(verify_csrf_token(''), 'Empty token must be rejected');
    }

    /**
     * Test Bcrypt Password Hashing Standard
     */
    public function test_bcrypt_password_hashing(): void {
        $plainPassword = 'admin123';
        $hashed = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        assert_true(password_verify($plainPassword, $hashed), 'Correct password must verify');
        assert_false(password_verify('wrongpassword', $hashed), 'Incorrect password must fail verification');
        
        $info = password_get_info($hashed);
        assert_equals('bcrypt', $info['algoName'], 'Algorithm must be bcrypt');
    }

    /**
     * Test RBAC Permission Segregation
     */
    public function test_rbac_permission_matrix(): void {
        $matrix = get_role_permissions();

        // 1. Admin must have all sensitive privileges
        assert_true(in_array('users_manage', $matrix[ROLE_ADMIN], true), 'Admin must have users_manage');
        assert_true(in_array('audit_view', $matrix[ROLE_ADMIN], true), 'Admin must have audit_view');
        assert_true(in_array('reports_net_income', $matrix[ROLE_ADMIN], true), 'Admin must have reports_net_income');

        // 2. Cashier must NEVER have sensitive financial or administrative privileges
        assert_false(in_array('users_manage', $matrix[ROLE_CASHIER], true), 'Cashier must NOT manage users');
        assert_false(in_array('expenses_manage', $matrix[ROLE_CASHIER], true), 'Cashier must NOT manage expenses');
        assert_false(in_array('reports_net_income', $matrix[ROLE_CASHIER], true), 'Cashier must NOT view net income');
        assert_false(in_array('audit_view', $matrix[ROLE_CASHIER], true), 'Cashier must NOT view audit logs');

        // 3. Cashier CAN create sales and view basic catalog
        assert_true(in_array('orders_create', $matrix[ROLE_CASHIER], true), 'Cashier must be able to create orders');
        assert_true(in_array('products_view', $matrix[ROLE_CASHIER], true), 'Cashier must be able to view products');
    }

    /**
     * Test Safe Session Logout and Termination Without Infinite Recursion
     */
    public function test_safe_logout_without_recursion(): void {
        require_once __DIR__ . '/../../includes/auth.php';

        // Simulate an expired session for user 1 (Admin)
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'admin';
        $_SESSION['last_activity'] = time() - (SESSION_LIFETIME + 500);

        // Invoking logout must tear down session cleanly without memory exhaustion
        logout();

        assert_true(empty($_SESSION), 'Session must be emptied after logout');
    }
}


