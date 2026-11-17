<?php
/**
 * Root Entrypoint Router
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/modules/dashboard/');
} else {
    header('Location: ' . BASE_URL . '/store/');
}
exit;

