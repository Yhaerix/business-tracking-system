<?php
/**
 * End-to-End Login Flow & Lockout Test
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

class LoginFlowTest {
    public function test_full_login_and_lockout_lifecycle(): void {
        $db = get_db();

        // Ensure admin user starts clean
        $db->query("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE username = 'admin'");

        // 1. Test Wrong Password
        $badRes = attempt_login('admin', 'wrong_password');
        assert_false($badRes['success'], 'Login with wrong password must fail');
        assert_equals('Invalid username or password', $badRes['message']);

        // Check failed attempts incremented to 1
        $stmt = $db->query("SELECT failed_logins FROM users WHERE username = 'admin'");
        assert_equals(1, (int)$stmt->fetchColumn());

        // 2. Trigger remaining 4 failed attempts to hit lockout (5 total)
        for ($i = 2; $i <= 5; $i++) {
            $lockRes = attempt_login('admin', 'wrong_password');
        }

        assert_false($lockRes['success']);
        assert_true(
            strpos($lockRes['message'], 'Account is temporarily locked') !== false ||
            strpos($lockRes['message'], 'Too many failed attempts') !== false,
            'Account should be locked after 5 attempts'
        );

        // Confirm locked_until is set in DB
        $stmtLocked = $db->query("SELECT locked_until FROM users WHERE username = 'admin'");
        assert_true(!empty($stmtLocked->fetchColumn()), 'locked_until must not be empty');

        // 3. Test that even with CORRECT password, locked account is rejected
        $lockedAttempt = attempt_login('admin', 'admin123');
        assert_false($lockedAttempt['success'], 'Locked account must be rejected even with right password');
        assert_true(strpos($lockedAttempt['message'], 'Account is temporarily locked') !== false);

        // 4. Reset lockout and test successful login with admin123
        $db->query("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE username = 'admin'");
        $goodRes = attempt_login('admin', 'admin123');
        assert_true($goodRes['success'], 'Login with admin123 must succeed after reset');
        assert_equals('admin', $goodRes['user']['username']);
        assert_equals('admin', $goodRes['user']['role_name']);
    }
}

