<?php
/**
 * Authentication & Session Management Guard
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';

/**
 * Initialize session with strict, defensive cookie parameters
 */
function init_session(): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $isSecure,
            'samesite' => 'Strict'
        ]);

        session_start();
    }

    // Check idle timeout (30 minutes)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
        unset($_SESSION['last_activity']);
        logout();
        header('Location: ' . BASE_URL . '/modules/auth/login.php?error=session_expired');
        exit;
    }

    $_SESSION['last_activity'] = time();
}

/**
 * Check if current visitor has a verified active session
 */
function is_logged_in(): bool {
    init_session();
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role_name']);
}

/**
 * Return current logged in user object/array
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }

    return [
        'id'        => (int)($_SESSION['user_id'] ?? 0),
        'username'  => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? ''),
        'role_id'   => (int)($_SESSION['role_id'] ?? 0),
        'role_name' => $_SESSION['role_name'] ?? '',
        'role_label'=> $_SESSION['role_label'] ?? ''
    ];
}

/**
 * Enforce authentication guard on protected pages
 */
function require_login(): void {
    if (!is_logged_in()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required', 'data' => null, 'errors' => []]);
            exit;
        }

        header('Location: ' . BASE_URL . '/modules/auth/login.php');
        exit;
    }
}

/**
 * Authenticate login credentials against MySQL with brute-force protection
 */
function attempt_login(string $username, string $password): array {
    $db = get_db();
    $username = trim($username);

    $stmt = $db->prepare("
        SELECT u.id, u.role_id, u.username, u.full_name, u.password_hash, 
               u.is_active, u.failed_logins, u.locked_until,
               r.name AS role_name, r.label AS role_label
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.username = :username
        LIMIT 1
    ");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid username or password'];
    }

    // Check account active state
    if ((int)$user['is_active'] !== 1) {
        return ['success' => false, 'message' => 'Your account has been deactivated. Please contact the administrator.'];
    }

    // Check brute-force lockout status
    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $remainingMinutes = ceil((strtotime($user['locked_until']) - time()) / 60);
        return [
            'success' => false, 
            'message' => "Account is temporarily locked due to multiple failed attempts. Please try again in {$remainingMinutes} minute(s)."
        ];
    }

    // Verify password hash
    if (!password_verify($password, $user['password_hash'])) {
        $newFailed = (int)$user['failed_logins'] + 1;
        $lockUntil = null;

        if ($newFailed >= MAX_LOGIN_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', time() + (LOCKOUT_DURATION_MINUTES * 60));
        }

        $stmtUpdate = $db->prepare("
            UPDATE users 
            SET failed_logins = :failed, locked_until = :lock_until 
            WHERE id = :id
        ");
        $stmtUpdate->execute([
            ':failed'     => $newFailed,
            ':lock_until' => $lockUntil,
            ':id'         => $user['id']
        ]);

        audit_log('auth', 'FAILED_LOGIN', (int)$user['id'], null, ['attempted_username' => $username]);

        if ($newFailed >= MAX_LOGIN_ATTEMPTS) {
            return [
                'success' => false, 
                'message' => "Too many failed attempts. Account locked for " . LOCKOUT_DURATION_MINUTES . " minutes."
            ];
        }

        return ['success' => false, 'message' => 'Invalid username or password'];
    }

    // Successful login: Reset failures and update timestamp
    $stmtReset = $db->prepare("
        UPDATE users 
        SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() 
        WHERE id = :id
    ");
    $stmtReset->execute([':id' => $user['id']]);

    // Prevent session fixation
    init_session();
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['role_id']       = (int)$user['role_id'];
    $_SESSION['role_name']     = $user['role_name'];
    $_SESSION['role_label']    = $user['role_label'];
    $_SESSION['last_activity'] = time();

    audit_log('auth', 'LOGIN', (int)$user['id'], null, ['username' => $user['username']]);

    return ['success' => true, 'message' => 'Login successful', 'user' => current_user()];
}

/**
 * Destroy session safely and tear down cookies
 */
function logout(): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    if (!empty($_SESSION['user_id'])) {
        audit_log('auth', 'LOGOUT', (int)$_SESSION['user_id'], null, null);
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies") && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), 
            '', 
            time() - 42000,
            $params["path"], 
            $params["domain"],
            $params["secure"], 
            $params["httponly"]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

