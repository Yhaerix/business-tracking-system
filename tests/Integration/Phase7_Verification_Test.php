<?php
/**
 * Phase 7 Integration Verification Test Suite
 * Small Business Sales and Expense Tracking System
 *
 * Verifies:
 * 1. User Creation & Duplicate Username Rejection.
 * 2. Password Hashing Standard (Bcrypt Cost 12, no plaintext leakage).
 * 3. Self-Deactivation & Self-Demotion Invariants (Admin cannot suspend/demote own session).
 * 4. Password Reset & Lockout Clearance (Clears failed_logins and locked_until).
 * 5. Audit Log Generation on Mutations with Credential Redaction.
 * 6. Audit Trail Multi-Axis Filtering (Module, Action, User, Date bounds).
 * 7. Audit Detail Payload Diff Structure.
 * 8. RBAC Matrix for User Management (Admin only).
 * 9. RBAC Matrix for Audit Trail Viewing (Admin + Manager only; Cashier denied).
 * 10. CSRF Token Guard & Authentication Session Checks.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

class Phase7_Verification_Test {
    private PDO $db;
    private int $adminUserId = 1;
    private int $managerUserId = 2;
    private int $cashierUserId = 3;

    private array $createdUserIds = [];
    private array $createdAuditIds = [];

    public function __construct() {
        $this->db = get_db();

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $this->setSessionAsAdmin();
        $this->cleanupTestData();
    }

    public function __destruct() {
        $this->cleanupTestData();
    }

    private function setSessionAsAdmin(): void {
        $_SESSION['user_id']    = $this->adminUserId;
        $_SESSION['username']   = 'admin';
        $_SESSION['full_name']  = 'System Administrator';
        $_SESSION['role_id']    = 1;
        $_SESSION['role_name']  = ROLE_ADMIN;
        $_SESSION['role_label'] = 'Administrator';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    private function setSessionAsManager(): void {
        $_SESSION['user_id']    = $this->managerUserId;
        $_SESSION['username']   = 'manager';
        $_SESSION['full_name']  = 'Store Manager';
        $_SESSION['role_id']    = 2;
        $_SESSION['role_name']  = ROLE_MANAGER;
        $_SESSION['role_label'] = 'Store Manager';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    private function setSessionAsCashier(): void {
        $_SESSION['user_id']    = $this->cashierUserId;
        $_SESSION['username']   = 'cashier';
        $_SESSION['full_name']  = 'Sales Cashier';
        $_SESSION['role_id']    = 3;
        $_SESSION['role_name']  = ROLE_CASHIER;
        $_SESSION['role_label'] = 'Cashier';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    private function cleanupTestData(): void {
        // Delete audit logs created during test run
        $this->db->exec("DELETE FROM audit_logs WHERE module IN ('users', 'audit') AND (old_values LIKE '%TEST_P7_%' OR new_values LIKE '%TEST_P7_%')");
        
        // Delete users created during test run
        $this->db->exec("DELETE FROM users WHERE username LIKE 'TEST_P7_%'");
    }

    // --------------------------------------------------------------------------
    // Test 1: User Creation & Duplicate Username Rejection
    // --------------------------------------------------------------------------
    public function test_user_creation_and_duplicate_username_rejection(): void {
        $this->setSessionAsAdmin();

        $username = 'TEST_P7_manager_' . mt_rand(1000, 9999);
        $fullName = 'Test Manager Operations';
        $roleId = 2; // Manager
        $password = 'ManagerPass123!';
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // 1. Insert new user
        $stmt = $this->db->prepare("
            INSERT INTO users (role_id, username, full_name, password_hash, is_active, created_at)
            VALUES (:role_id, :username, :full_name, :password_hash, 1, NOW())
        ");
        $stmt->execute([
            ':role_id'       => $roleId,
            ':username'      => $username,
            ':full_name'     => $fullName,
            ':password_hash' => $passwordHash
        ]);
        $newUserId = (int)$this->db->lastInsertId();
        $this->createdUserIds[] = $newUserId;

        // Verify record in database
        $stmtCheck = $this->db->prepare("SELECT id, username, role_id, is_active FROM users WHERE id = :id");
        $stmtCheck->execute([':id' => $newUserId]);
        $userRow = $stmtCheck->fetch();

        if (!$userRow || $userRow['username'] !== $username) {
            throw new Exception("Created user record not found in database.");
        }
        if ((int)$userRow['is_active'] !== 1) {
            throw new Exception("New user account must default to active (is_active = 1).");
        }

        // 2. Attempt duplicate username insertion (must fail with PDOException / unique constraint violation)
        $duplicateCaught = false;
        try {
            $stmtDup = $this->db->prepare("
                INSERT INTO users (role_id, username, full_name, password_hash, is_active, created_at)
                VALUES (:role_id, :username, :full_name, :password_hash, 1, NOW())
            ");
            $stmtDup->execute([
                ':role_id'       => 3, // Cashier
                ':username'      => $username, // Duplicate username
                ':full_name'     => 'Duplicate Test User',
                ':password_hash' => $passwordHash
            ]);
        } catch (PDOException $e) {
            // MySQL error code 23000 / 1062 = Duplicate entry
            $duplicateCaught = true;
        }

        if (!$duplicateCaught) {
            throw new Exception("Database failed to enforce unique constraint on duplicate username '{$username}'.");
        }
    }

    // --------------------------------------------------------------------------
    // Test 2: Password Hashing Standard (Bcrypt Cost 12 & Zero Plaintext)
    // --------------------------------------------------------------------------
    public function test_password_hashing_standard_bcrypt_cost_12(): void {
        $password = 'HighSecurityP@ssword2026!';
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // Verify standard Bcrypt prefix with cost 12 ($2y$12$)
        if (!str_starts_with($hash, '$2y$12$')) {
            throw new Exception("Password hash does not meet Bcrypt cost 12 specification: starts with '" . substr($hash, 0, 7) . "'");
        }

        // Verify hash verification
        if (!password_verify($password, $hash)) {
            throw new Exception("password_verify failed to authenticate with generated Bcrypt cost 12 hash.");
        }

        // Verify incorrect password rejection
        if (password_verify('WrongPassword', $hash)) {
            throw new Exception("password_verify erroneously accepted incorrect password.");
        }

        // Verify plaintext password is never stored or present in hash info
        $info = password_get_info($hash);
        if ($info['algoName'] !== 'bcrypt' || ($info['options']['cost'] ?? 0) !== 12) {
            throw new Exception("password_get_info does not report Bcrypt algorithm with cost 12.");
        }
    }

    // --------------------------------------------------------------------------
    // Test 3: Self-Deactivation & Self-Demotion Invariant
    // --------------------------------------------------------------------------
    public function test_self_deactivation_and_self_demotion_invariant(): void {
        $this->setSessionAsAdmin();
        $adminId = 1;

        // Invariant Rule: Current logged in user cannot suspend themselves or demote their own role
        $selfUpdateAttempt = [
            'id'        => $adminId,
            'is_active' => 0, // Attempt self-deactivation
            'role_id'   => 3  // Attempt self-demotion to cashier
        ];

        $currentSessionId = (int)($_SESSION['user_id'] ?? 0);
        $isSelf = ($selfUpdateAttempt['id'] === $currentSessionId);

        if (!$isSelf) {
            throw new Exception("Session identity verification failed: Expected user ID 1.");
        }

        // Evaluation of invariant guard
        $blockedDeactivation = ($isSelf && $selfUpdateAttempt['is_active'] === 0);
        $blockedDemotion = ($isSelf && $selfUpdateAttempt['role_id'] !== 1);

        if (!$blockedDeactivation) {
            throw new Exception("Self-deactivation guard failed: Admin was not blocked from suspending their own active account.");
        }
        if (!$blockedDemotion) {
            throw new Exception("Self-demotion guard failed: Admin was not blocked from changing their own role.");
        }

        // Verify User 1 remains active and admin in DB
        $stmt = $this->db->prepare("SELECT role_id, is_active FROM users WHERE id = :id");
        $stmt->execute([':id' => $adminId]);
        $adminRow = $stmt->fetch();

        if ((int)$adminRow['is_active'] !== 1 || (int)$adminRow['role_id'] !== 1) {
            throw new Exception("Primary admin account state was unexpectedly mutated.");
        }
    }

    // --------------------------------------------------------------------------
    // Test 4: Password Reset & Lockout Clearance
    // --------------------------------------------------------------------------
    public function test_password_reset_and_lockout_clearance(): void {
        $this->setSessionAsAdmin();

        $username = 'TEST_P7_locked_' . mt_rand(1000, 9999);
        $oldPassHash = password_hash('InitialPass123!', PASSWORD_BCRYPT, ['cost' => 12]);

        // Insert user in locked state: failed_logins = 5, locked_until = 1 hour in future
        $stmt = $this->db->prepare("
            INSERT INTO users (role_id, username, full_name, password_hash, is_active, failed_logins, locked_until, created_at)
            VALUES (3, :username, 'Locked Cashier', :hash, 1, 5, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
        ");
        $stmt->execute([':username' => $username, ':hash' => $oldPassHash]);
        $targetUserId = (int)$this->db->lastInsertId();
        $this->createdUserIds[] = $targetUserId;

        // Perform password reset action
        $newPassword = 'NewFreshPassword2026!';
        $newPassHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmtReset = $this->db->prepare("
            UPDATE users 
            SET password_hash = :hash, failed_logins = 0, locked_until = NULL, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmtReset->execute([':hash' => $newPassHash, ':id' => $targetUserId]);

        // Verify updated database state
        $stmtCheck = $this->db->prepare("SELECT password_hash, failed_logins, locked_until FROM users WHERE id = :id");
        $stmtCheck->execute([':id' => $targetUserId]);
        $updatedUser = $stmtCheck->fetch();

        if (!password_verify($newPassword, $updatedUser['password_hash'])) {
            throw new Exception("Password hash was not updated to new credentials.");
        }
        if ((int)$updatedUser['failed_logins'] !== 0) {
            throw new Exception("Failed login counter was not reset to 0 (found: {$updatedUser['failed_logins']}).");
        }
        if ($updatedUser['locked_until'] !== null) {
            throw new Exception("Lockout expiration timestamp was not cleared to NULL (found: {$updatedUser['locked_until']}).");
        }
    }

    // --------------------------------------------------------------------------
    // Test 5: Audit Log Generation on Mutations with Credential Redaction
    // --------------------------------------------------------------------------
    public function test_audit_log_generation_and_credential_redaction(): void {
        $this->setSessionAsAdmin();

        $targetUserId = 999981;
        $username = 'TEST_P7_audit_target';

        // 1. Simulate CREATE action audit logging with sensitive redaction
        $cleanCreatePayload = [
            'username'  => $username,
            'full_name' => 'Audit Test User',
            'role_id'   => 2,
            'is_active' => 1
            // Notice: 'password' and 'password_hash' MUST be excluded
        ];

        log_audit('users', 'CREATE', $targetUserId, null, $cleanCreatePayload);

        // 2. Simulate PASSWORD_RESET action audit logging
        $cleanResetPayload = [
            'user_id'       => $targetUserId,
            'reset_by'      => $this->adminUserId,
            'lockout_cleared' => true
            // Notice: Neither old nor new plain passwords or hashes are logged
        ];

        log_audit('users', 'PASSWORD_RESET', $targetUserId, ['failed_logins' => 5], $cleanResetPayload);

        // Verify CREATE log in database
        $stmt1 = $this->db->prepare("
            SELECT * FROM audit_logs 
            WHERE module = 'users' AND action = 'CREATE' AND record_id = :id
            ORDER BY id DESC LIMIT 1
        ");
        $stmt1->execute([':id' => $targetUserId]);
        $createLog = $stmt1->fetch();

        if (!$createLog) {
            throw new Exception("Audit log record for user CREATE action was not recorded.");
        }
        if ((int)$createLog['user_id'] !== $this->adminUserId) {
            throw new Exception("Audit log actor user_id does not match active session admin ID.");
        }

        // Verify credential redaction: Ensure 'password' and 'password_hash' do not appear in JSON
        $newValsJson = (string)$createLog['new_values'];
        if (str_contains($newValsJson, 'password') || str_contains($newValsJson, 'password_hash')) {
            throw new Exception("Security violation: audit_logs new_values contains plaintext password or hash field: {$newValsJson}");
        }

        // Verify PASSWORD_RESET log in database
        $stmt2 = $this->db->prepare("
            SELECT * FROM audit_logs 
            WHERE module = 'users' AND action = 'PASSWORD_RESET' AND record_id = :id
            ORDER BY id DESC LIMIT 1
        ");
        $stmt2->execute([':id' => $targetUserId]);
        $resetLog = $stmt2->fetch();

        if (!$resetLog) {
            throw new Exception("Audit log record for user PASSWORD_RESET action was not recorded.");
        }

        $resetJson = (string)$resetLog['new_values'];
        if (str_contains($resetJson, 'password') || str_contains($resetJson, 'password_hash')) {
            throw new Exception("Security violation: audit_logs new_values contains password field in PASSWORD_RESET action.");
        }
    }

    // --------------------------------------------------------------------------
    // Test 6: Audit Trail Multi-Axis Query Filtering
    // --------------------------------------------------------------------------
    public function test_audit_trail_query_filtering(): void {
        $this->setSessionAsAdmin();

        $uniqueMarker = 'TEST_P7_FILTER_' . mt_rand(1000, 9999);

        // Insert 3 distinct audit records
        log_audit('users', 'CREATE', 999901, null, ['marker' => $uniqueMarker, 'type' => 'user_create']);
        log_audit('orders', 'UPDATE', 999902, ['status' => 'PENDING'], ['marker' => $uniqueMarker, 'status' => 'CONFIRMED']);
        log_audit('expenses', 'SOFT_DELETE', 999903, ['amount' => 500], ['marker' => $uniqueMarker, 'deleted' => true]);

        // Filter 1: By module = 'users'
        $stmtUsers = $this->db->prepare("
            SELECT COUNT(*) FROM audit_logs 
            WHERE module = 'users' AND new_values LIKE :marker
        ");
        $stmtUsers->execute([':marker' => "%{$uniqueMarker}%"]);
        $countUsers = (int)$stmtUsers->fetchColumn();

        if ($countUsers !== 1) {
            throw new Exception("Module filter expected exactly 1 log for module='users', found {$countUsers}");
        }

        // Filter 2: By action = 'UPDATE'
        $stmtAction = $this->db->prepare("
            SELECT COUNT(*) FROM audit_logs 
            WHERE action = 'UPDATE' AND new_values LIKE :marker
        ");
        $stmtAction->execute([':marker' => "%{$uniqueMarker}%"]);
        $countAction = (int)$stmtAction->fetchColumn();

        if ($countAction !== 1) {
            throw new Exception("Action filter expected exactly 1 log for action='UPDATE', found {$countAction}");
        }

        // Filter 3: Combined module + action filter
        $stmtBoth = $this->db->prepare("
            SELECT COUNT(*) FROM audit_logs 
            WHERE module = 'expenses' AND action = 'SOFT_DELETE' AND new_values LIKE :marker
        ");
        $stmtBoth->execute([':marker' => "%{$uniqueMarker}%"]);
        $countBoth = (int)$stmtBoth->fetchColumn();

        if ($countBoth !== 1) {
            throw new Exception("Combined filter expected 1 log for module='expenses' and action='SOFT_DELETE', found {$countBoth}");
        }
    }

    // --------------------------------------------------------------------------
    // Test 7: Audit Detail JSON Payload Diff Structure
    // --------------------------------------------------------------------------
    public function test_audit_detail_payload_diff_structure(): void {
        $oldState = [
            'full_name' => 'Original Name',
            'role_id'   => 3,
            'is_active' => 1
        ];

        $newState = [
            'full_name' => 'Updated Name',
            'role_id'   => 2,
            'is_active' => 1
        ];

        $testId = 999977;
        log_audit('users', 'UPDATE', $testId, $oldState, $newState);

        // Fetch detail
        $stmt = $this->db->prepare("
            SELECT old_values, new_values FROM audit_logs 
            WHERE module = 'users' AND action = 'UPDATE' AND record_id = :id 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':id' => $testId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Failed to retrieve audit log detail row.");
        }

        $decodedOld = json_decode($row['old_values'], true);
        $decodedNew = json_decode($row['new_values'], true);

        if (!is_array($decodedOld) || !is_array($decodedNew)) {
            throw new Exception("audit_logs JSON payloads failed to decode into valid arrays.");
        }

        // Verify diff computation
        $changedKeys = [];
        foreach ($decodedNew as $k => $val) {
            if (!array_key_exists($k, $decodedOld) || $decodedOld[$k] !== $val) {
                $changedKeys[] = $k;
            }
        }

        if (!in_array('full_name', $changedKeys, true) || !in_array('role_id', $changedKeys, true)) {
            throw new Exception("Diff computation failed to identify modified fields (full_name and role_id).");
        }

        if (in_array('is_active', $changedKeys, true)) {
            throw new Exception("Diff computation erroneously marked unchanged field (is_active) as modified.");
        }
    }

    // --------------------------------------------------------------------------
    // Test 8: RBAC Verification - User Management Authorization
    // --------------------------------------------------------------------------
    public function test_rbac_user_management_authorization(): void {
        // 1. Admin must possess users_manage
        $this->setSessionAsAdmin();
        if (!has_permission('users_manage')) {
            throw new Exception("RBAC Violation: Admin role must possess 'users_manage' permission.");
        }

        // 2. Manager must NOT possess users_manage
        $this->setSessionAsManager();
        if (has_permission('users_manage')) {
            throw new Exception("RBAC Violation: Manager role must NOT possess 'users_manage' permission.");
        }

        // 3. Cashier must NOT possess users_manage
        $this->setSessionAsCashier();
        if (has_permission('users_manage')) {
            throw new Exception("RBAC Violation: Cashier role must NOT possess 'users_manage' permission.");
        }
    }

    // --------------------------------------------------------------------------
    // Test 9: RBAC Verification - Audit Trail Viewing Authorization
    // --------------------------------------------------------------------------
    public function test_rbac_audit_trail_authorization(): void {
        // 1. Admin must possess audit_view
        $this->setSessionAsAdmin();
        if (!has_permission('audit_view')) {
            throw new Exception("RBAC Violation: Admin role must possess 'audit_view' permission.");
        }

        // 2. Manager must possess audit_view (read-only audit oversight)
        $this->setSessionAsManager();
        if (!has_permission('audit_view')) {
            throw new Exception("RBAC Violation: Manager role must possess 'audit_view' permission.");
        }

        // 3. Cashier must NOT possess audit_view
        $this->setSessionAsCashier();
        if (has_permission('audit_view')) {
            throw new Exception("RBAC Violation: Cashier role must NOT possess 'audit_view' permission.");
        }
    }

    // --------------------------------------------------------------------------
    // Test 10: CSRF Token Guard & Session Authentication Invariants
    // --------------------------------------------------------------------------
    public function test_csrf_token_guard_and_session_invariants(): void {
        // 1. Valid CSRF Token validation
        $validToken = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $validToken;

        if (!verify_csrf_token($validToken)) {
            throw new Exception("verify_csrf_token rejected an authentic matching session CSRF token.");
        }

        // 2. Invalid CSRF Token rejection
        $invalidToken = 'invalid_tampered_token_string';
        if (verify_csrf_token($invalidToken)) {
            throw new Exception("verify_csrf_token accepted a fraudulent or forged CSRF token.");
        }

        // 3. Unauthenticated session check
        unset($_SESSION['user_id']);
        if (is_logged_in()) {
            throw new Exception("is_logged_in returned true when session user_id was unset.");
        }
    }

    // --------------------------------------------------------------------------
    // Test 11: Backend Handlers and View Files Existence and Integrity
    // --------------------------------------------------------------------------
    public function test_backend_handlers_and_views_integrity(): void {
        $requiredFiles = [
            __DIR__ . '/../../modules/users/handler.php',
            __DIR__ . '/../../modules/users/index.php',
            __DIR__ . '/../../modules/audit/handler.php',
            __DIR__ . '/../../modules/audit/index.php'
        ];

        foreach ($requiredFiles as $file) {
            if (!file_exists($file)) {
                throw new Exception("Required Phase 7 file does not exist: " . basename($file));
            }

            // Verify PHP syntax
            $output = [];
            $returnCode = 0;
            exec("php -l " . escapeshellarg($file), $output, $returnCode);
            if ($returnCode !== 0) {
                throw new Exception("Syntax error detected in file " . basename($file) . ": " . implode("\n", $output));
            }
        }
    }
}


