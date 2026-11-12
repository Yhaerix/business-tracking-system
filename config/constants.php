<?php
/**
 * System Global Constants
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

// Application Identity
define('APP_NAME', 'Sales & Expense Tracker');
define('APP_VERSION', '1.0.0');

// Currency & Financial Standards (Excludes tax calculations per requirements)
define('APP_CURRENCY', '₱');
define('CURRENCY_SYMBOL', APP_CURRENCY);
define('APP_CURRENCY_CODE', 'PHP');
define('APP_TIMEZONE', 'Asia/Manila');

// Set Application Default Timezone
date_default_timezone_set(APP_TIMEZONE);

// Base URLs and Paths
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', '/Business%20Tracking%20System');

// Security & Session Settings
define('SESSION_LIFETIME', 1800); // 30 minutes in seconds
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION_MINUTES', 15);

// Order Status Constants (Strict COD State Machine)
define('ORDER_STATUS_PENDING', 'PENDING');
define('ORDER_STATUS_CONFIRMED', 'CONFIRMED');
define('ORDER_STATUS_DISPATCHED', 'DISPATCHED');
define('ORDER_STATUS_DELIVERED', 'DELIVERED');
define('ORDER_STATUS_CANCELLED', 'CANCELLED');
define('ORDER_STATUS_RETURNED', 'RETURNED');

// Payment Status Constants (Strictly COD)
define('PAYMENT_STATUS_PENDING', 'PENDING_COLLECTION');
define('PAYMENT_STATUS_COLLECTED', 'COLLECTED');
define('PAYMENT_STATUS_REMITTED', 'REMITTED');
define('PAYMENT_STATUS_FAILED', 'FAILED');

// Role Names
define('ROLE_ADMIN', 'admin');
define('ROLE_MANAGER', 'manager');
define('ROLE_CASHIER', 'cashier');

