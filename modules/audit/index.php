<?php
/**
 * Redirect Legacy Audit URL to Settings Module (Audit Logs Tab)
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';

// Redirect to System Settings Audit Logs tab without duplicating views
header('Location: ' . BASE_URL . '/modules/settings/#audit', true, 301);
exit;
