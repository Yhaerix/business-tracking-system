<?php
/**
 * Authentication View: Login
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

// Redirect to dashboard if already authenticated
if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/modules/dashboard/');
    exit;
}

$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title>Sign In — <?= APP_NAME ?></title>
    
    <!-- Google Fonts: Inter (400, 500, 600, 700, 800) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">

    <style>
        :root {
            --auth-bg: #eef2fa;
            --auth-card-bg: #ffffff;
            --auth-border: #e4e9f4;
            --auth-text-main: #0f172a;
            --auth-text-secondary: #64748b;
            --auth-text-muted: #94a3b8;
            --auth-primary: #2160cc;
            --auth-primary-hover: #1a4fb0;
            --auth-focus-ring: rgba(33, 96, 204, 0.16);
        }

        html, body {
            height: 100%;
        }

        body {
            background-color: var(--auth-bg);
            background-image: 
                radial-gradient(at 0% 0%, rgba(33, 96, 204, 0.06) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(33, 96, 204, 0.05) 0px, transparent 50%);
            color: var(--auth-text-main);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 32px 20px;
            font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Subtle ambient pattern on crisp white */
        .auth-wrapper {
            width: 100%;
            max-width: 420px;
            margin: auto;
        }

        .auth-brand-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .auth-logo-badge {
            width: 48px;
            height: 48px;
            margin: 0 auto 16px;
            background: #2160cc;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            box-shadow: 0 8px 20px rgba(33, 96, 204, 0.25);
        }

        .auth-logo-badge svg {
            width: 24px;
            height: 24px;
        }

        .auth-title {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--auth-text-main);
            margin-bottom: 6px;
        }

        .auth-subtitle {
            font-size: 13.5px;
            color: var(--auth-text-secondary);
            font-weight: 400;
            line-height: 1.45;
        }

        /* Main Card */
        .auth-card {
            background: var(--auth-card-bg);
            border: 1px solid var(--auth-border);
            border-radius: 24px;
            padding: 36px 32px;
            box-shadow: 0 14px 40px -10px rgba(33, 96, 204, 0.12), 0 2px 10px rgba(15, 23, 42, 0.04);
        }

        /* Inline Alert */
        .auth-alert {
            display: none;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 14px;
            margin-bottom: 22px;
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            border-radius: 12px;
            color: #991b1b;
            font-size: 13px;
            line-height: 1.45;
            animation: fadeIn 0.2s ease-out;
        }

        .auth-alert-icon {
            flex-shrink: 0;
            margin-top: 1px;
            color: #dc2626;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Form Controls */
        .auth-form-group {
            margin-bottom: 20px;
        }

        .auth-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 7px;
        }

        .auth-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .auth-input-icon {
            position: absolute;
            left: 14px;
            color: #94a3b8;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s ease;
        }

        .auth-input {
            width: 100%;
            height: 46px;
            padding: 0 14px 0 42px;
            font-size: 14px;
            color: #0f172a;
            background: #ffffff;
            border: 1px solid #d8e1f0;
            border-radius: 12px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            -webkit-appearance: none;
        }

        .auth-input::placeholder {
            color: #94a3b8;
        }

        .auth-input:focus {
            outline: none;
            border-color: var(--auth-primary);
            box-shadow: 0 0 0 3.5px var(--auth-focus-ring);
        }

        .auth-input-wrapper:focus-within .auth-input-icon {
            color: var(--auth-primary);
        }

        /* Password Toggle */
        .auth-input-wrapper.has-toggle .auth-input {
            padding-right: 44px;
        }

        .auth-password-toggle {
            position: absolute;
            right: 12px;
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: color 0.15s ease, background 0.15s ease;
        }

        .auth-password-toggle:hover {
            color: var(--auth-primary);
            background: #eff6ff;
        }

        .auth-password-toggle:focus {
            outline: none;
            color: var(--auth-primary);
        }

        /* Submit Button */
        .auth-submit-btn {
            width: 100%;
            height: 46px;
            background: var(--auth-primary);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 14.5px;
            font-weight: 600;
            letter-spacing: -0.01em;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
            transition: all 0.15s ease;
            box-shadow: 0 4px 14px rgba(33, 96, 204, 0.32);
        }

        .auth-submit-btn:hover:not(:disabled) {
            background: var(--auth-primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(33, 96, 204, 0.38);
        }

        .auth-submit-btn:active:not(:disabled) {
            transform: translateY(0);
            background: #153e8a;
        }

        .auth-submit-btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
        }

        /* Loading Spinner */
        .auth-spinner {
            display: none;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Auto-Fill Credential Helper */
        .demo-sandbox-card {
            margin-top: 20px;
            padding: 10px 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .demo-credentials-text {
            font-size: 12.5px;
            color: #64748b;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .demo-credentials-text strong {
            color: #0f172a;
            font-weight: 700;
        }

        .btn-fill-demo {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #2160cc;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }

        .btn-fill-demo:hover {
            background: #eff6ff;
            border-color: #2160cc;
            color: #1a4fb0;
        }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <!-- Brand Header -->
        <header class="auth-brand-header">
            <div style="margin-bottom: 20px;">
                <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="Business Tracker System Logo" width="104" height="104" style="display: block; margin: 0 auto; filter: drop-shadow(0 10px 24px rgba(37, 99, 235, 0.28));">
            </div>
            <h1 class="auth-title">Sign in to your account</h1>
            <p class="auth-subtitle"><?= APP_NAME ?> &bull; Financial & Sales Operations</p>
        </header>

        <!-- Authentication Card -->
        <main class="auth-card">
            <!-- Inline Error Banner -->
            <div id="alertBox" class="auth-alert" style="display: <?= !empty($error) ? 'flex' : 'none' ?>;" role="alert">
                <svg class="auth-alert-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span id="alertMessage">
                    <?php if ($error === 'session_expired'): ?>
                        Your session has expired. Please sign in again.
                    <?php else: ?>
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </span>
            </div>

            <!-- Login Form -->
            <form id="loginForm" method="POST" action="handler.php" autocomplete="on">
                <?= csrf_field() ?>

                <div class="auth-form-group">
                    <label for="username" class="auth-label">Username</label>
                    <div class="auth-input-wrapper">
                        <span class="auth-input-icon" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </span>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            class="auth-input" 
                            required 
                            autofocus 
                            autocomplete="username"
                            placeholder="Enter your username"
                        >
                    </div>
                </div>

                <div class="auth-form-group">
                    <label for="password" class="auth-label">Password</label>
                    <div class="auth-input-wrapper has-toggle">
                        <span class="auth-input-icon" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                        </span>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="auth-input" 
                            required 
                            autocomplete="current-password"
                            placeholder="Enter your password"
                        >
                        <button type="button" id="passwordToggleBtn" class="auth-password-toggle" aria-label="Toggle password visibility">
                            <svg id="eyeIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg id="eyeOffIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                                <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>
                                <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>
                                <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>
                                <line x1="2" y1="2" x2="22" y2="22"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" id="submitBtn" class="auth-submit-btn">
                    <span class="auth-spinner" id="btnSpinner" aria-hidden="true"></span>
                    <span id="btnText">Sign In</span>
                </button>
            </form>

            <!-- Auto-fill Credential Helper -->
            <div class="demo-sandbox-card">
                <div class="demo-credentials-text">
                    <strong>admin</strong> / <span>admin123</span>
                </div>
                <button type="button" class="btn-fill-demo" id="fillDemoBtn" title="Auto-fill credentials">
                    Auto-fill
                </button>
            </div>
        </main>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/api.js"></script>
    <script>
        // Password Visibility Toggle
        const passwordInput = document.getElementById('password');
        const passwordToggleBtn = document.getElementById('passwordToggleBtn');
        const eyeIcon = document.getElementById('eyeIcon');
        const eyeOffIcon = document.getElementById('eyeOffIcon');

        if (passwordToggleBtn && passwordInput) {
            passwordToggleBtn.addEventListener('click', () => {
                const isPassword = passwordInput.getAttribute('type') === 'password';
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                eyeIcon.style.display = isPassword ? 'none' : 'block';
                eyeOffIcon.style.display = isPassword ? 'block' : 'none';
                passwordToggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        }

        // Auto-fill Credential Helper
        const fillDemoBtn = document.getElementById('fillDemoBtn');
        const usernameInput = document.getElementById('username');

        if (fillDemoBtn && usernameInput && passwordInput) {
            fillDemoBtn.addEventListener('click', () => {
                usernameInput.value = 'admin';
                passwordInput.value = 'admin123';
                usernameInput.focus();

                const originalText = fillDemoBtn.textContent;
                fillDemoBtn.textContent = 'Filled!';
                fillDemoBtn.style.borderColor = '#2160cc';
                fillDemoBtn.style.color = '#2160cc';
                setTimeout(() => {
                    fillDemoBtn.textContent = originalText;
                    fillDemoBtn.style.borderColor = '';
                    fillDemoBtn.style.color = '';
                }, 1000);
            });
        }

        // Form Submission with Loading State
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const btnSpinner = document.getElementById('btnSpinner');
            const alertBox = document.getElementById('alertBox');
            const alertMsg = document.getElementById('alertMessage');

            // Set loading state
            submitBtn.disabled = true;
            btnSpinner.style.display = 'inline-block';
            btnText.textContent = 'Verifying...';
            alertBox.style.display = 'none';

            const formData = new FormData(form);
            const response = await API.post(form.action, formData);

            if (response.success) {
                btnText.textContent = 'Success!';
                btnSpinner.style.display = 'none';
                API.toast('Login successful! Redirecting...', 'success');
                window.location.href = '<?= BASE_URL ?>/modules/dashboard/';
            } else {
                alertMsg.textContent = response.message || 'Invalid username or password.';
                alertBox.style.display = 'flex';
                submitBtn.disabled = false;
                btnSpinner.style.display = 'none';
                btnText.textContent = 'Sign In';

                // Keep username, clear password and refocus
                if (passwordInput) {
                    passwordInput.value = '';
                    passwordInput.focus();
                }
            }
        });
    </script>
</body>
</html>
