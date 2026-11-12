<?php
/**
 * 403 Forbidden / Access Denied Screen
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';

$pageTitle = 'Access Denied';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">
    <style>
        body {
            background-color: var(--bg-body);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .error-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-modal);
            max-width: 480px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .error-code {
            font-size: 54px;
            font-weight: 800;
            color: var(--color-danger);
            line-height: 1;
            margin-bottom: 12px;
        }
        .error-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .error-desc {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 24px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-code">403</div>
        <h1 class="error-title">Access Forbidden</h1>
        <p class="error-desc">
            You do not have the required permissions to view this resource. If you believe this is an error, please contact your store manager or administrator.
        </p>
        <a href="<?= BASE_URL ?>/modules/dashboard/" class="btn btn-primary">
            Return to Dashboard
        </a>
    </div>
</body>
</html>

