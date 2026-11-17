<?php
/**
 * Authentication & Session Management Guard
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mailer.php';

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
 * Mask an email address for public/UI privacy
 * e.g. admin@prestigeapple.ph -> a***n@prestigeapple.ph
 */
function mask_email(string $email): string {
    $parts = explode('@', trim($email));
    if (count($parts) !== 2) {
        return $email;
    }
    $name = $parts[0];
    $domain = $parts[1];
    $len = mb_strlen($name);
    if ($len <= 2) {
        $maskedName = mb_substr($name, 0, 1) . '***';
    } else {
        $maskedName = mb_substr($name, 0, 1) . str_repeat('*', min(4, $len - 2)) . mb_substr($name, -1);
    }
    return $maskedName . '@' . $domain;
}

/**
 * Generate, record, and dispatch a 6-digit OTP code for a user
 */
function generate_user_otp(array $user, string $purpose = 'login'): array {
    $db = get_db();
    $userId = (int)$user['id'];
    $email = !empty($user['email']) ? trim($user['email']) : 'admin@prestigeapple.ph';
    $fullName = $user['full_name'] ?? $user['username'];

    // Invalidate previous unconsumed OTPs for this user & purpose
    $stmtInvalidate = $db->prepare("
        UPDATE auth_otps 
        SET is_used = 1 
        WHERE user_id = :uid AND user_type = 'admin' AND purpose = :purpose AND is_used = 0
    ");
    $stmtInvalidate->execute([
        ':uid'     => $userId,
        ':purpose' => $purpose
    ]);

    // Generate cryptographically strong 6-digit OTP
    $otpCode = sprintf('%06d', random_int(100000, 999999));
    $otpHash = password_hash($otpCode, PASSWORD_BCRYPT);
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes expiry

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Web Browser';

    $stmtInsert = $db->prepare("
        INSERT INTO auth_otps 
            (user_type, user_id, email, otp_code_hash, otp_code_plain, purpose, expires_at, ip_address, user_agent)
        VALUES 
            ('admin', :uid, :email, :code_hash, :code_plain, :purpose, :expires_at, :ip, :ua)
    ");
    $stmtInsert->execute([
        ':uid'        => $userId,
        ':email'      => $email,
        ':code_hash'  => $otpHash,
        ':code_plain' => $otpCode,
        ':purpose'    => $purpose,
        ':expires_at' => $expiresAt,
        ':ip'         => $ipAddress,
        ':ua'         => $userAgent
    ]);

    // Dispatch email
    $mailResult = send_otp_email($email, $fullName, $otpCode, 10);

    // Save temporary state in session
    init_session();
    $_SESSION['pending_otp'] = [
        'user_id'    => $userId,
        'email'      => $email,
        'full_name'  => $fullName,
        'time'       => time(),
        'purpose'    => $purpose
    ];

    audit_log('auth', 'OTP_GENERATED', $userId, null, [
        'email'   => $email,
        'purpose' => $purpose,
        'mode'    => $mailResult['mode'] ?? 'unknown'
    ]);

    return [
        'success'      => true,
        'require_otp'  => true,
        'masked_email' => mask_email($email),
        'dev_otp'      => $otpCode, // Available in local sandbox testing
        'message'      => 'A 6-digit verification code has been sent to ' . mask_email($email) . '.'
    ];
}

/**
 * Verify 6-digit login OTP and establish authenticated session
 */
function verify_login_otp(string $otpCode): array {
    init_session();

    $pending = $_SESSION['pending_otp'] ?? null;
    if (empty($pending['user_id'])) {
        return [
            'success' => false,
            'message' => 'Login session expired or invalid. Please sign in again.'
        ];
    }

    $userId = (int)$pending['user_id'];
    $otpCode = trim($otpCode);

    if (empty($otpCode) || !preg_match('/^[0-9]{6}$/', $otpCode)) {
        return [
            'success' => false,
            'message' => 'Please enter a valid 6-digit verification code.'
        ];
    }

    $db = get_db();

    // Fetch latest active OTP for user
    $stmt = $db->prepare("
        SELECT id, otp_code_hash, otp_code_plain, attempts, max_attempts, expires_at, is_used 
        FROM auth_otps 
        WHERE user_id = :uid AND user_type = 'admin' AND purpose = 'login' AND is_used = 0 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId]);
    $otp = $stmt->fetch();

    if (!$otp) {
        return [
            'success' => false,
            'message' => 'No active verification code found. Please request a new code.'
        ];
    }

    // Check expiration
    if (strtotime($otp['expires_at']) < time()) {
        $stmtExp = $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE id = :id");
        $stmtExp->execute([':id' => $otp['id']]);
        return [
            'success' => false,
            'message' => 'Verification code has expired. Please request a new code.'
        ];
    }

    // Check max attempts
    if ((int)$otp['attempts'] >= (int)$otp['max_attempts']) {
        $stmtLock = $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE id = :id");
        $stmtLock->execute([':id' => $otp['id']]);
        return [
            'success' => false,
            'message' => 'Too many failed attempts. This code has been invalidated. Please request a new code.'
        ];
    }

    // Verify OTP matching
    $matched = password_verify($otpCode, $otp['otp_code_hash']) || ($otpCode === $otp['otp_code_plain']);

    if (!$matched) {
        $newAttempts = (int)$otp['attempts'] + 1;
        $stmtInc = $db->prepare("UPDATE auth_otps SET attempts = :attempts WHERE id = :id");
        $stmtInc->execute([':attempts' => $newAttempts, ':id' => $otp['id']]);

        $remaining = (int)$otp['max_attempts'] - $newAttempts;
        $attemptMsg = $remaining > 0 ? " ({$remaining} attempt(s) remaining)" : "";

        return [
            'success' => false,
            'message' => 'Invalid verification code.' . $attemptMsg
        ];
    }

    // Consume the OTP
    $stmtConsume = $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE id = :id");
    $stmtConsume->execute([':id' => $otp['id']]);

    // Load full user details
    $stmtUser = $db->prepare("
        SELECT u.id, u.role_id, u.username, u.full_name, u.email, u.is_active,
               r.name AS role_name, r.label AS role_label
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = :uid
        LIMIT 1
    ");
    $stmtUser->execute([':uid' => $userId]);
    $user = $stmtUser->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        return [
            'success' => false,
            'message' => 'Account is inactive or could not be found.'
        ];
    }

    // Reset failed logins & update last_login_at
    $stmtReset = $db->prepare("
        UPDATE users 
        SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() 
        WHERE id = :id
    ");
    $stmtReset->execute([':id' => $user['id']]);

    // Prevent session fixation
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id']              = (int)$user['id'];
    $_SESSION['username']             = $user['username'];
    $_SESSION['full_name']            = $user['full_name'];
    $_SESSION['email']                = $user['email'];
    $_SESSION['role_id']              = (int)$user['role_id'];
    $_SESSION['role_name']            = $user['role_name'];
    $_SESSION['role_label']           = $user['role_label'];
    $_SESSION['last_activity']        = time();
    $_SESSION['show_welcome_banner']  = true; // Flag for dashboard welcome banner
    $_SESSION['active_store_identity'] = 'admin';

    unset(
        $_SESSION['pending_otp'],
        $_SESSION['customer_id'],
        $_SESSION['customer_name'],
        $_SESSION['customer_email'],
        $_SESSION['customer_phone'],
        $_SESSION['customer_coins']
    );

    // Send Login Welcome & Security Alert Email
    $userEmail = !empty($user['email']) ? $user['email'] : 'admin@prestigeapple.ph';
    send_login_welcome_email($userEmail, $user['full_name'], $user['role_label'], [
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Web Browser'
    ]);

    audit_log('auth', 'LOGIN', (int)$user['id'], null, [
        'username' => $user['username'],
        'method'   => 'PASSWORD_AND_OTP'
    ]);

    return [
        'success'        => true,
        'message'        => 'Verification successful! Welcome back.',
        'redirect'       => BASE_URL . '/modules/dashboard/',
        'welcome_banner' => true,
        'user'           => current_user()
    ];
}

/**
 * Resend a new OTP to the pending user (with 60-second cooldown)
 */
function resend_login_otp(): array {
    init_session();

    $pending = $_SESSION['pending_otp'] ?? null;
    if (empty($pending['user_id'])) {
        return [
            'success' => false,
            'message' => 'No pending login session. Please start over from the sign in page.'
        ];
    }

    // Cooldown check (60 seconds)
    $lastSent = (int)($pending['time'] ?? 0);
    $cooldown = 60;
    if (time() - $lastSent < $cooldown) {
        $remaining = $cooldown - (time() - $lastSent);
        return [
            'success' => false,
            'message' => "Please wait {$remaining} second(s) before requesting another code."
        ];
    }

    $db = get_db();
    $stmt = $db->prepare("SELECT id, username, full_name, email FROM users WHERE id = :uid LIMIT 1");
    $stmt->execute([':uid' => (int)$pending['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'User account not found.'];
    }

    return generate_user_otp($user, 'login');
}

/**
 * Authenticate login credentials against MySQL with brute-force protection
 * Optional $requireOtp determines whether to trigger 2FA OTP verification
 */
function attempt_login(string $username, string $password, bool $requireOtp = false): array {
    $db = get_db();
    $username = trim($username);

    $stmt = $db->prepare("
        SELECT u.id, u.role_id, u.username, u.full_name, u.email, u.password_hash, 
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

    // If OTP verification is required (e.g. standard admin web login)
    if ($requireOtp) {
        // Reset password failure count
        $stmtReset = $db->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = :id");
        $stmtReset->execute([':id' => $user['id']]);

        return generate_user_otp($user, 'login');
    }

    // Direct login (when OTP is explicitly disabled or in CLI test environments)
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

    $_SESSION['user_id']              = (int)$user['id'];
    $_SESSION['username']             = $user['username'];
    $_SESSION['full_name']            = $user['full_name'];
    $_SESSION['email']                = $user['email'];
    $_SESSION['role_id']              = (int)$user['role_id'];
    $_SESSION['role_name']            = $user['role_name'];
    $_SESSION['role_label']           = $user['role_label'];
    $_SESSION['last_activity']        = time();
    $_SESSION['show_welcome_banner']  = true;
    $_SESSION['active_store_identity'] = 'admin';

    unset(
        $_SESSION['customer_id'],
        $_SESSION['customer_name'],
        $_SESSION['customer_email'],
        $_SESSION['customer_phone'],
        $_SESSION['customer_coins']
    );

    audit_log('auth', 'LOGIN', (int)$user['id'], null, ['username' => $user['username']]);

    return ['success' => true, 'message' => 'Login successful', 'user' => current_user()];
}

/**
 * Synchronize active Admin session with the Public Storefront account context
 * so when an Admin opens the Public Storefront, it shows the Admin user account
 * instead of a leftover customer account.
 */
function sync_admin_storefront_session(?PDO $db = null): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        return;
    }

    // If admin explicitly clicked "Public Storefront" from admin panel, force admin identity
    if (!empty($_GET['admin_session'])) {
        $_SESSION['active_store_identity'] = 'admin';
    }

    // Default to 'admin' when an admin is logged in, unless a customer explicitly signed in on the storefront
    $identity = $_SESSION['active_store_identity'] ?? 'admin';
    if ($identity !== 'admin') {
        return;
    }

    $_SESSION['active_store_identity'] = 'admin';
    $adminUserId = (int)$_SESSION['user_id'];

    try {
        if ($db === null) {
            $db = Database::getConnection();
        }

        $stmtAdmin = $db->prepare("
            SELECT u.id, u.username, u.full_name, u.email, r.label AS role_label
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = :uid AND u.is_active = 1
            LIMIT 1
        ");
        $stmtAdmin->execute([':uid' => $adminUserId]);
        $adminUser = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

        if (!$adminUser) {
            return;
        }

        $adminName  = trim((string)($adminUser['full_name'] ?: $adminUser['username']));
        $adminEmail = trim((string)($adminUser['email'] ?: ($adminUser['username'] . '@prestigeapple.ph')));

        // Find or create a dedicated customer profile row for this admin user
        $stmtCust = $db->prepare("SELECT id, name, email, phone, wallet_balance FROM customers WHERE email = :email AND deleted_at IS NULL LIMIT 1");
        $stmtCust->execute([':email' => $adminEmail]);
        $adminCust = $stmtCust->fetch(PDO::FETCH_ASSOC);

        if (!$adminCust) {
            $randomHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $stmtIns = $db->prepare("
                INSERT INTO customers (name, email, phone, password_hash, wallet_balance, status, email_verified_at, created_at)
                VALUES (:name, :email, :phone, :pass, 0.00, 'active', NOW(), NOW())
            ");
            $stmtIns->execute([
                ':name'  => $adminName,
                ':email' => $adminEmail,
                ':phone' => '09170000000',
                ':pass'  => $randomHash
            ]);
            $adminCustId = (int)$db->lastInsertId();

            $stmtWallet = $db->prepare("
                INSERT INTO customer_wallets (customer_id, coin_balance, is_frozen, created_at)
                VALUES (:cid, 0.00, 0, NOW())
            ");
            $stmtWallet->execute([':cid' => $adminCustId]);
            $adminPhone = '09170000000';
            $adminCoins = 0.00;
        } else {
            $adminCustId = (int)$adminCust['id'];
            if ($adminCust['name'] !== $adminName) {
                $stmtUpd = $db->prepare("UPDATE customers SET name = :name WHERE id = :id");
                $stmtUpd->execute([':name' => $adminName, ':id' => $adminCustId]);
            }
            $adminPhone = (string)($adminCust['phone'] ?? '09170000000');
            $adminCoins = (float)($adminCust['wallet_balance'] ?? 0.00);
        }

        $_SESSION['customer_id']            = $adminCustId;
        $_SESSION['customer_name']          = $adminName;
        $_SESSION['customer_email']         = $adminEmail;
        $_SESSION['customer_phone']         = $adminPhone;
        $_SESSION['customer_coins']         = $adminCoins;
        $_SESSION['is_admin_store_account'] = true;
    } catch (\Throwable $e) {
        // Fallback to session values if DB is unreachable
        $_SESSION['customer_name']          = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin');
        $_SESSION['customer_email']         = $_SESSION['email'] ?? 'admin@prestigeapple.ph';
        $_SESSION['is_admin_store_account'] = true;
    }
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

