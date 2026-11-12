<?php
/**
 * Global Top Header & Layout Shell
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/rbac.php';

require_login();
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' — ' : '' ?><?= APP_NAME ?></title>
    
    <!-- Design System Stylesheets -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Backdrop for Mobile Drawer -->
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <!-- Role-Aware Sidebar Navigation -->
        <?php include __DIR__ . '/sidebar.php'; ?>

        <!-- Main Content Area -->
        <div class="app-main">
            <!-- Top Navbar -->
            <header class="app-header">
                <div class="header-left">
                    <button type="button" class="menu-toggle" id="menuToggle" aria-label="Toggle navigation">
                        ☰
                    </button>
                    <span class="page-breadcrumb">
                        <?= isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'Dashboard' ?>
                    </span>
                </div>
                <div class="header-right">
                    <span class="badge badge-confirmed">
                        Role: <?= htmlspecialchars($user['role_label'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <a href="<?= BASE_URL ?>/modules/auth/logout.php" class="btn btn-outline btn-sm">
                        Logout
                    </a>
                </div>
            </header>

            <!-- Page Body Container -->
            <main class="page-content">

