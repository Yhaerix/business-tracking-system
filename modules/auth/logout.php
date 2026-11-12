<?php
/**
 * Safe Session Logout Endpoint
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';

logout();

header('Location: ' . BASE_URL . '/modules/auth/login.php');
exit;

