<?php
/**
 * User Management & Authentication Administration Backend Handler
 * Small Business Sales and Expense Tracking System
 *
 * Actions:
 * - list_users      (GET/POST, Permission: users_manage)
 * - get_roles       (GET/POST, Permission: users_manage)
 * - create_user     (POST,     Permission: users_manage)
 * - update_user     (POST,     Permission: users_manage)
 * - reset_password  (POST,     Permission: users_manage)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

// Helper: Standardized JSON envelope
function send_json(bool $success, string $message, $data = null, array $errors = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
        'errors'  => $errors
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: Extract request payload (supports JSON and application/x-www-form-urlencoded / FormData)
function get_request_data(): array
{
    $data = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = $json;
        }
    } else {
        $data = $_POST;
    }
    return array_merge($_GET, $data);
}

// Helper: Enforce Anti-CSRF Token
function require_csrf(array $input): void
{
    $token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verify_csrf_token($token)) {
        send_json(false, 'Security token invalid or expired. Please refresh the page.', null, ['csrf' => 'Invalid CSRF token'], 403);
    }
}

// 1. Session Authentication & Admin Permission Guard (Admin only)
require_login();
require_permission('users_manage');

$input = get_request_data();
$action = trim((string) ($input['action'] ?? ''));
$db = get_db();
$currentUser = current_user();

try {
    switch ($action) {
        // =====================================================================
        // 1. LIST USERS
        // =====================================================================
        case 'list_users':
            $search = trim((string) ($input['search'] ?? ''));
            $roleId = !empty($input['role_id']) ? (int) $input['role_id'] : null;
            $status = isset($input['status']) && $input['status'] !== '' ? (string) $input['status'] : (isset($input['is_active']) && $input['is_active'] !== '' ? (string) $input['is_active'] : null);

            $where = ['1=1'];
            $params = [];

            if ($search !== '') {
                $where[] = '(u.username LIKE :search OR u.full_name LIKE :search)';
                $params[':search'] = '%' . $search . '%';
            }

            if ($roleId !== null && $roleId > 0) {
                $where[] = 'u.role_id = :role_id';
                $params[':role_id'] = $roleId;
            }

            if ($status !== null) {
                if ($status === 'active' || $status === '1') {
                    $where[] = 'u.is_active = 1';
                } elseif ($status === 'inactive' || $status === '0') {
                    $where[] = 'u.is_active = 0';
                }
            }

            $whereSql = implode(' AND ', $where);

            $stmt = $db->prepare("
                SELECT u.id, u.username, u.full_name, u.role_id,
                       r.name AS role_name, r.label AS role_label,
                       u.is_active, u.failed_logins, u.locked_until,
                       u.last_login_at, u.created_at, u.updated_at
                FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE {$whereSql}
                ORDER BY u.id ASC
            ");
            $stmt->execute($params);
            $rawUsers = $stmt->fetchAll();

            $users = [];
            $currentTime = time();
            foreach ($rawUsers as $row) {
                $isLocked = !empty($row['locked_until']) && (strtotime($row['locked_until']) > $currentTime);
                $users[] = [
                    'id'            => (int) $row['id'],
                    'username'      => $row['username'],
                    'full_name'     => $row['full_name'],
                    'role_id'       => (int) $row['role_id'],
                    'role_name'     => $row['role_name'],
                    'role_label'    => $row['role_label'],
                    'is_active'     => (int) $row['is_active'],
                    'failed_logins' => (int) $row['failed_logins'],
                    'locked_until'  => $row['locked_until'],
                    'is_locked'     => $isLocked,
                    'last_login_at' => $row['last_login_at'],
                    'created_at'    => $row['created_at'],
                    'updated_at'    => $row['updated_at']
                ];
            }

            send_json(true, 'Users retrieved successfully', [
                'users' => $users,
                'total' => count($users)
            ]);
            break;

        // =====================================================================
        // 2. GET ROLES
        // =====================================================================
        case 'get_roles':
            $stmt = $db->query("
                SELECT id, name, label, description
                FROM roles
                ORDER BY id ASC
            ");
            $rawRoles = $stmt->fetchAll();

            $roles = [];
            foreach ($rawRoles as $row) {
                $roles[] = [
                    'id'          => (int) $row['id'],
                    'name'        => $row['name'],
                    'label'       => $row['label'],
                    'description' => $row['description']
                ];
            }

            send_json(true, 'Roles retrieved successfully', $roles);
            break;

        // =====================================================================
        // 3. CREATE USER
        // =====================================================================
        case 'create_user':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            require_csrf($input);

            $username = trim((string) ($input['username'] ?? ''));
            $fullName = trim((string) ($input['full_name'] ?? ''));
            $password = (string) ($input['password'] ?? '');
            $roleId = (int) ($input['role_id'] ?? 0);
            $isActive = isset($input['is_active']) ? ((int) $input['is_active'] === 0 ? 0 : 1) : 1;

            $errors = [];

            // Username validation: 3-30 chars, alphanumeric + underscores
            if ($username === '') {
                $errors['username'] = 'Username is required.';
            } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
                $errors['username'] = 'Username must be 3-30 characters long and contain only letters, numbers, and underscores.';
            } else {
                $stmtCheck = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1");
                $stmtCheck->execute([':username' => $username]);
                if ($stmtCheck->fetch()) {
                    $errors['username'] = 'Username is already taken by another account.';
                }
            }

            // Full Name validation: 2-100 chars
            if ($fullName === '') {
                $errors['full_name'] = 'Full name is required.';
            } elseif (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
                $errors['full_name'] = 'Full name must be between 2 and 100 characters.';
            }

            // Password validation: min 6 chars
            if (strlen($password) < 6) {
                $errors['password'] = 'Password must be at least 6 characters long.';
            }

            // Role ID validation
            if ($roleId <= 0) {
                $errors['role_id'] = 'Valid role selection is required.';
            } else {
                $stmtRole = $db->prepare("SELECT id, name, label FROM roles WHERE id = :id LIMIT 1");
                $stmtRole->execute([':id' => $roleId]);
                $roleRow = $stmtRole->fetch();
                if (!$roleRow) {
                    $errors['role_id'] = 'Selected role does not exist.';
                }
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            // Bcrypt password hash (cost 12)
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $stmtInsert = $db->prepare("
                INSERT INTO users (
                    role_id, username, full_name, password_hash, is_active, created_at
                ) VALUES (
                    :role_id, :username, :full_name, :password_hash, :is_active, NOW()
                )
            ");
            $stmtInsert->execute([
                ':role_id'       => $roleId,
                ':username'      => $username,
                ':full_name'     => $fullName,
                ':password_hash' => $passwordHash,
                ':is_active'     => $isActive
            ]);
            $newUserId = (int) $db->lastInsertId();

            audit_log('users', 'CREATE', $newUserId, null, [
                'username'  => $username,
                'full_name' => $fullName,
                'role_id'   => $roleId,
                'is_active' => $isActive
            ]);

            send_json(true, 'User account created successfully', [
                'id'         => $newUserId,
                'username'   => $username,
                'full_name'  => $fullName,
                'role_id'    => $roleId,
                'role_name'  => $roleRow['name'] ?? '',
                'role_label' => $roleRow['label'] ?? '',
                'is_active'  => $isActive
            ], [], 201);
            break;

        // =====================================================================
        // 4. UPDATE USER
        // =====================================================================
        case 'update_user':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            require_csrf($input);

            $userId = (int) ($input['user_id'] ?? $input['id'] ?? 0);
            $fullName = trim((string) ($input['full_name'] ?? ''));
            $roleId = (int) ($input['role_id'] ?? 0);
            $isActive = isset($input['is_active']) ? ((int) $input['is_active'] === 0 ? 0 : 1) : 1;

            if ($userId <= 0) {
                send_json(false, 'Valid User ID is required.', null, ['user_id' => 'Invalid ID'], 422);
            }

            // Fetch existing user record
            $stmtFind = $db->prepare("
                SELECT u.id, u.username, u.full_name, u.role_id, u.is_active,
                       r.name AS role_name, r.label AS role_label
                FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE u.id = :id
                LIMIT 1
            ");
            $stmtFind->execute([':id' => $userId]);
            $existing = $stmtFind->fetch();

            if (!$existing) {
                send_json(false, 'User not found.', null, [], 404);
            }

            $errors = [];

            // Full Name validation
            if ($fullName === '') {
                $errors['full_name'] = 'Full name is required.';
            } elseif (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
                $errors['full_name'] = 'Full name must be between 2 and 100 characters.';
            }

            // Role validation
            if ($roleId <= 0) {
                $errors['role_id'] = 'Valid role selection is required.';
            } else {
                $stmtTargetRole = $db->prepare("SELECT id, name, label FROM roles WHERE id = :id LIMIT 1");
                $stmtTargetRole->execute([':id' => $roleId]);
                $targetRole = $stmtTargetRole->fetch();
                if (!$targetRole) {
                    $errors['role_id'] = 'Selected role does not exist.';
                }
            }

            // Guard: Self-Deactivation Guard
            if ($userId === (int) $currentUser['id'] && $isActive === 0) {
                send_json(false, 'You cannot deactivate your own account.', null, [
                    'is_active' => 'Self-deactivation is prohibited.'
                ], 400);
            }

            // Guard: Self-Demotion Guard (Admins cannot revoke their own admin role)
            if ($userId === (int) $currentUser['id'] && isset($targetRole) && $targetRole['name'] !== ROLE_ADMIN) {
                send_json(false, 'You cannot demote or change your own administrative role.', null, [
                    'role_id' => 'Self-demotion is prohibited.'
                ], 400);
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            // Update user record
            $stmtUpdate = $db->prepare("
                UPDATE users
                SET full_name = :full_name,
                    role_id = :role_id,
                    is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':full_name' => $fullName,
                ':role_id'   => $roleId,
                ':is_active' => $isActive,
                ':id'        => $userId
            ]);

            audit_log('users', 'UPDATE', $userId, [
                'full_name' => $existing['full_name'],
                'role_id'   => (int) $existing['role_id'],
                'is_active' => (int) $existing['is_active']
            ], [
                'full_name' => $fullName,
                'role_id'   => $roleId,
                'is_active' => $isActive
            ]);

            send_json(true, 'User details updated successfully', [
                'id'         => $userId,
                'username'   => $existing['username'],
                'full_name'  => $fullName,
                'role_id'    => $roleId,
                'role_name'  => $targetRole['name'] ?? '',
                'role_label' => $targetRole['label'] ?? '',
                'is_active'  => $isActive
            ]);
            break;

        // =====================================================================
        // 5. RESET PASSWORD
        // =====================================================================
        case 'reset_password':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            require_csrf($input);

            $userId = (int) ($input['user_id'] ?? $input['id'] ?? 0);
            $newPassword = (string) ($input['new_password'] ?? $input['password'] ?? '');
            $confirmPassword = (string) ($input['confirm_password'] ?? '');

            if ($userId <= 0) {
                send_json(false, 'Valid User ID is required.', null, ['user_id' => 'Invalid ID'], 422);
            }

            $stmtUser = $db->prepare("SELECT id, username FROM users WHERE id = :id LIMIT 1");
            $stmtUser->execute([':id' => $userId]);
            $userRow = $stmtUser->fetch();

            if (!$userRow) {
                send_json(false, 'User not found.', null, [], 404);
            }

            $errors = [];

            if (strlen($newPassword) < 6) {
                $errors['new_password'] = 'New password must be at least 6 characters long.';
            }

            if ($newPassword !== $confirmPassword) {
                $errors['confirm_password'] = 'New password and confirmation password do not match.';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            // Bcrypt hash
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

            // Reset password and clear lockout status
            $stmtReset = $db->prepare("
                UPDATE users
                SET password_hash = :hash,
                    failed_logins = 0,
                    locked_until = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtReset->execute([
                ':hash' => $newHash,
                ':id'   => $userId
            ]);

            // Record password reset in audit logs (Never log plaintext password or hash!)
            audit_log('users', 'PASSWORD_RESET', $userId, null, [
                'reset_by' => $currentUser['username']
            ]);

            send_json(true, 'User password has been successfully reset and account lockout cleared.', [
                'user_id' => $userId
            ]);
            break;

        default:
            send_json(false, 'Invalid or unrecognized action requested.', null, ['action' => $action], 400);
            break;
    }
} catch (PDOException $e) {
    error_log('[Users Handler Database Error] ' . $e->getMessage());
    send_json(false, 'A database error occurred while processing user data.', null, [], 500);
} catch (Throwable $e) {
    error_log('[Users Handler System Error] ' . $e->getMessage());
    send_json(false, 'An unexpected server error occurred.', null, [], 500);
}

