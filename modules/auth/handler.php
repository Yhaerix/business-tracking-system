<?php
/**
 * Authentication Action Handler (POST)
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed', 'data' => null, 'errors' => []]);
    exit;
}

// 1. Verify Anti-CSRF Token
$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf_token($token)) {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'message' => 'Security token invalid or expired. Please refresh the page.', 
        'data'    => null, 
        'errors'  => []
    ]);
    exit;
}

// 2. Validate Inputs
$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if (empty($username) || empty($password)) {
    http_response_code(422);
    echo json_encode([
        'success' => false, 
        'message' => 'Please provide both username and password.', 
        'data'    => null, 
        'errors'  => []
    ]);
    exit;
}

// 3. Attempt Authentication
$result = attempt_login($username, $password);

if (!$result['success']) {
    http_response_code(401);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
exit;

