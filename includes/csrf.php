<?php
/**
 * Cross-Site Request Forgery (CSRF) Protection
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

/**
 * Generate or retrieve the session CSRF token
 */
function get_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token using timing-safe comparison
 */
function verify_csrf_token(?string $token): bool {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Render a hidden HTML CSRF input field
 */
function csrf_field(): string {
    $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}
