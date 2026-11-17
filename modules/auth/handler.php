<?php
/**
 * Authentication Action Handler (POST)
 * Small Business Sales and Expense Tracking System
 * 
 * Supports:
 * - action = 'login' (or default username+password): Credential check + OTP generation & email dispatch
 * - action = 'verify_otp': 6-digit OTP verification + session initialization + welcome alert email
 * - action = 'resend_otp': Rate-limited OTP resend to pending user
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

ob_start();

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

function send_auth_json(int $code, array $payload): void {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_auth_json(405, ['success' => false, 'message' => 'Method not allowed', 'data' => null, 'errors' => []]);
}

// Support JSON payload or standard POST
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = [];
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
} else {
    $input = $_POST;
}

// 1. Verify Anti-CSRF Token
$token = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf_token($token)) {
    send_auth_json(403, [
        'success' => false, 
        'message' => 'Security token invalid or expired. Please refresh the page.', 
        'data'    => null, 
        'errors'  => []
    ]);
}

$action = trim((string)($input['action'] ?? 'login'));

// =========================================================================
// ACTION: VERIFY OTP
// =========================================================================
if ($action === 'verify_otp') {
    $otpCode = trim((string)($input['otp_code'] ?? ''));
    if (empty($otpCode)) {
        send_auth_json(422, [
            'success' => false,
            'message' => 'Please enter the 6-digit verification code.',
            'data'    => null
        ]);
    }

    $result = verify_login_otp($otpCode);
    $statusCode = $result['success'] ? 200 : 401;
    send_auth_json($statusCode, $result);
}

// =========================================================================
// ACTION: RESEND OTP
// =========================================================================
if ($action === 'resend_otp') {
    $result = resend_login_otp();
    $statusCode = $result['success'] ? 200 : 429;
    send_auth_json($statusCode, $result);
}

// =========================================================================
// ACTION: CREDENTIALS LOGIN (STEP 1 -> OTP)
// =========================================================================
$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');

if (empty($username) || empty($password)) {
    send_auth_json(422, [
        'success' => false, 
        'message' => 'Please provide both username and password.', 
        'data'    => null, 
        'errors'  => []
    ]);
}

// Step 1: Attempt login with OTP verification enforced
$result = attempt_login($username, $password, true);
$statusCode = $result['success'] ? 200 : 401;

send_auth_json($statusCode, $result);
