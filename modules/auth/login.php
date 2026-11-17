<?php
/**
 * Authentication View: Login with Two-Factor OTP Verification
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

// Redirect to dashboard if already authenticated
if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/modules/dashboard/');
    exit;
}

$error = $_GET['error'] ?? '';

// Dynamic store name resolution
$authStoreName = defined('APP_NAME') ? APP_NAME : 'Store Panel';
try {
    $db = get_db();
    $stmtStore = $db->query("SELECT setting_value FROM store_settings WHERE setting_key = 'store_name' LIMIT 1");
    $dbStoreVal = $stmtStore ? $stmtStore->fetchColumn() : null;
    if (!empty($dbStoreVal)) {
        $authStoreName = (string)$dbStoreVal;
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title>Sign In — <?= htmlspecialchars($authStoreName, ENT_QUOTES, 'UTF-8') ?></title>
    
    <!-- Google Fonts: Inter -->
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

        .auth-wrapper {
            width: 100%;
            max-width: 440px;
            margin: auto;
        }

        .auth-brand-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .auth-card {
            background: var(--auth-card-bg);
            border: 1px solid var(--auth-border);
            border-radius: 20px;
            padding: 32px 28px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06), 0 1px 3px rgba(15, 23, 42, 0.04);
            position: relative;
            overflow: hidden;
        }

        .auth-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--auth-text-main);
            letter-spacing: -0.02em;
            margin: 0 0 6px 0;
            text-align: center;
        }

        .auth-subtitle {
            font-size: 13.5px;
            color: var(--auth-text-secondary);
            margin: 0 0 24px 0;
            text-align: center;
            line-height: 1.5;
        }

        /* Inline Error Banner */
        .auth-alert {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            background: #fef2f2;
            border: 1px solid #fee2e2;
            border-radius: 10px;
            color: #b91c1c;
            font-size: 13px;
            margin-bottom: 20px;
            line-height: 1.4;
        }

        .auth-alert.success {
            background: #f0fdf4;
            border-color: #dcfce7;
            color: #15803d;
        }

        .auth-form-group {
            margin-bottom: 18px;
        }

        .auth-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--auth-text-main);
            margin-bottom: 6px;
        }

        .auth-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .auth-input-icon {
            position: absolute;
            left: 14px;
            color: var(--auth-text-muted);
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .auth-input {
            width: 100%;
            height: 44px;
            padding: 0 14px 0 40px;
            border: 1.5px solid var(--auth-border);
            border-radius: 10px;
            font-size: 14px;
            color: var(--auth-text-main);
            background: #ffffff;
            transition: all 0.15s ease;
        }

        .auth-input:focus {
            outline: none;
            border-color: var(--auth-primary);
            box-shadow: 0 0 0 3.5px var(--auth-focus-ring);
        }

        .auth-input-wrapper.has-toggle .auth-input {
            padding-right: 44px;
        }

        .auth-password-toggle {
            position: absolute;
            right: 10px;
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }

        .auth-password-toggle:hover {
            color: var(--auth-primary);
            background: #eff6ff;
        }

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
            box-shadow: 0 4px 14px rgba(33, 96, 204, 0.28);
        }

        .auth-submit-btn:hover:not(:disabled) {
            background: var(--auth-primary-hover);
            transform: translateY(-1px);
        }

        .auth-submit-btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

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

        /* 6-Digit OTP Styles */
        .otp-icon-badge {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: #eff6ff;
            color: #2160cc;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .otp-inputs-grid {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin: 20px 0 24px 0;
        }

        .otp-digit {
            width: 48px;
            height: 56px;
            font-size: 24px;
            font-weight: 700;
            text-align: center;
            border: 1.5px solid var(--auth-border);
            border-radius: 12px;
            background: #f8fafc;
            color: var(--auth-text-main);
            transition: all 0.15s ease;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .otp-digit:focus {
            outline: none;
            border-color: var(--auth-primary);
            background: #ffffff;
            box-shadow: 0 0 0 3.5px var(--auth-focus-ring);
        }

        .otp-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 16px;
            font-size: 13px;
            color: var(--auth-text-secondary);
        }

        .btn-resend-otp {
            background: none;
            border: none;
            color: var(--auth-primary);
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            font-size: 13px;
        }

        .btn-resend-otp:disabled {
            color: var(--auth-text-muted);
            cursor: not-allowed;
        }

        .btn-back-login {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: none;
            color: var(--auth-text-secondary);
            font-size: 13px;
            cursor: pointer;
            padding: 0;
        }

        .btn-back-login:hover {
            color: var(--auth-primary);
        }

        /* Demo credentials helper */
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
            font-size: 12px;
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
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-fill-demo:hover {
            background: #eff6ff;
            border-color: #2160cc;
        }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <!-- Brand Header -->
        <header class="auth-brand-header">
            <div style="margin-bottom: 16px;">
                <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="<?= htmlspecialchars($authStoreName, ENT_QUOTES, 'UTF-8') ?> Logo" width="96" height="96" style="display: block; margin: 0 auto; filter: drop-shadow(0 10px 24px rgba(37, 99, 235, 0.28));">
            </div>
            <h1 class="auth-title" id="pageMainTitle"><?= htmlspecialchars($authStoreName, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="auth-subtitle" id="pageSubTitle">Sign in to your account &bull; Operations &amp; Financial Management</p>
        </header>

        <!-- Main Authentication Card -->
        <main class="auth-card">
            <!-- Inline Error / Notification Banner -->
            <div id="alertBox" class="auth-alert" style="display: <?= !empty($error) ? 'flex' : 'none' ?>;" role="alert">
                <svg id="alertIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                </svg>
                <span id="alertMessage">
                    <?php if ($error === 'session_expired'): ?>
                        Your session has expired. Please sign in again.
                    <?php else: ?>
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </span>
            </div>

            <!-- ============================================================
                 STEP 1: CREDENTIALS (USERNAME + PASSWORD)
                 ============================================================ -->
            <div id="credentialsStep">
                <form id="loginForm" method="POST" action="handler.php" autocomplete="on">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="login">

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
                                placeholder="Enter username (e.g. admin)"
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
                        <span id="btnText">Sign In →</span>
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
            </div>

            <!-- ============================================================
                 STEP 2: 6-DIGIT OTP VERIFICATION
                 ============================================================ -->
            <div id="otpStep" style="display: none;">
                <div class="otp-icon-badge">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        <circle cx="12" cy="16" r="1"/>
                    </svg>
                </div>

                <div style="text-align: center; margin-bottom: 20px;">
                    <div style="font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 4px;">
                        Enter Verification Code
                    </div>
                    <div style="font-size: 13px; color: #64748b; line-height: 1.5;">
                        We sent a 6-digit security code to:<br>
                        <strong id="otpTargetEmail" style="color: #0f172a;">jandyellemendoza@gmail.com</strong>
                    </div>
                </div>

                <form id="otpForm" onsubmit="handleOtpSubmit(event)">
                    <div class="otp-inputs-grid" id="otpInputsContainer">
                        <input type="text" class="otp-digit" id="otp1" maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" autofocus>
                        <input type="text" class="otp-digit" id="otp2" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                        <input type="text" class="otp-digit" id="otp3" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                        <input type="text" class="otp-digit" id="otp4" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                        <input type="text" class="otp-digit" id="otp5" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                        <input type="text" class="otp-digit" id="otp6" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                    </div>

                    <button type="submit" id="otpSubmitBtn" class="auth-submit-btn">
                        <span class="auth-spinner" id="otpSpinner" aria-hidden="true"></span>
                        <span id="otpBtnText">Verify & Continue</span>
                    </button>
                </form>

                <!-- Resend & Back Row -->
                <div class="otp-meta-row">
                    <button type="button" class="btn-back-login" onclick="backToCredentials()">
                        &larr; Back to sign in
                    </button>
                    <div>
                        <span id="timerText">Resend in <strong id="countdownSpan">60</strong>s</span>
                        <button type="button" id="btnResendOtp" class="btn-resend-otp" style="display: none;" onclick="handleResendOtp()">
                            Resend Code
                        </button>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/api.js"></script>
    <script>
        const credentialsStep = document.getElementById('credentialsStep');
        const otpStep = document.getElementById('otpStep');
        const pageMainTitle = document.getElementById('pageMainTitle');
        const pageSubTitle = document.getElementById('pageSubTitle');
        const alertBox = document.getElementById('alertBox');
        const alertMsg = document.getElementById('alertMessage');
        const otpTargetEmail = document.getElementById('otpTargetEmail');

        let resendTimer = null;
        let countdownSeconds = 60;

        function showAlert(msg, isSuccess = false) {
            alertMsg.textContent = msg;
            alertBox.className = 'auth-alert' + (isSuccess ? ' success' : '');
            alertBox.style.display = 'flex';
        }

        function hideAlert() {
            alertBox.style.display = 'none';
        }

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
            });
        }

        const usernameInput = document.getElementById('username');
        const fillDemoBtn = document.getElementById('fillDemoBtn');

        if (fillDemoBtn && usernameInput && passwordInput) {
            fillDemoBtn.addEventListener('click', () => {
                usernameInput.value = 'admin';
                passwordInput.value = 'admin123';
                usernameInput.focus();
                const originalText = fillDemoBtn.textContent;
                fillDemoBtn.textContent = 'Filled!';
                fillDemoBtn.style.color = '#2160cc';
                setTimeout(() => {
                    fillDemoBtn.textContent = originalText;
                    fillDemoBtn.style.color = '';
                }, 1000);
            });
        }

        

        // STEP 1: Submit Credentials
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const btnSpinner = document.getElementById('btnSpinner');

            submitBtn.disabled = true;
            btnSpinner.style.display = 'inline-block';
            btnText.textContent = 'Verifying credentials...';
            hideAlert();

            try {
                const response = await API.post('handler.php', {
                    action: 'login',
                    username: usernameInput.value.trim(),
                    password: passwordInput.value,
                    csrf_token: API.getCSRFToken()
                });

                if (response.success && response.require_otp) {
                    // Transition to OTP step
                    switchToOtpStep(response);
                } else if (response.success && !response.require_otp) {
                    btnText.textContent = 'Success!';
                    window.location.href = '<?= BASE_URL ?>/modules/dashboard/';
                } else {
                    showAlert(response.message || 'Invalid username or password.');
                    submitBtn.disabled = false;
                    btnSpinner.style.display = 'none';
                    btnText.textContent = 'Sign In →';
                    if (passwordInput) {
                        passwordInput.value = '';
                        passwordInput.focus();
                    }
                }
            } catch (err) {
                console.error(err);
                showAlert('Network or connection error. Please try again.');
                submitBtn.disabled = false;
                btnSpinner.style.display = 'none';
                btnText.textContent = 'Sign In →';
            }
        });

        function switchToOtpStep(res) {
            credentialsStep.style.display = 'none';
            otpStep.style.display = 'block';
            pageMainTitle.textContent = 'Two-Factor Authentication';
            pageSubTitle.textContent = 'A one-time verification code has been dispatched to your email.';
            otpTargetEmail.textContent = res.masked_email || 'your registered email';

            

            hideAlert();
            startResendCountdown(60);

            // Clear digits & focus first
            const digits = document.querySelectorAll('.otp-digit');
            digits.forEach(d => d.value = '');
            setTimeout(() => {
                document.getElementById('otp1')?.focus();
            }, 100);
        }

        function backToCredentials() {
            otpStep.style.display = 'none';
            credentialsStep.style.display = 'block';
            pageMainTitle.textContent = <?= json_encode($authStoreName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            pageSubTitle.textContent = 'Sign in to your account • Operations & Financial Management';
            hideAlert();

            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const btnSpinner = document.getElementById('btnSpinner');
            submitBtn.disabled = false;
            btnSpinner.style.display = 'none';
            btnText.textContent = 'Sign In →';

            if (resendTimer) clearInterval(resendTimer);
        }

        // OTP Digits Handling (Auto-focus, Backspace, Paste)
        const otpInputs = [
            document.getElementById('otp1'),
            document.getElementById('otp2'),
            document.getElementById('otp3'),
            document.getElementById('otp4'),
            document.getElementById('otp5'),
            document.getElementById('otp6')
        ];

        otpInputs.forEach((input, idx) => {
            // Auto advance
            input.addEventListener('input', (e) => {
                const val = input.value.replace(/[^0-9]/g, '');
                input.value = val ? val[0] : '';
                if (val && idx < 5) {
                    otpInputs[idx + 1].focus();
                }

                // If all 6 filled, auto submit
                checkAndAutoSubmit();
            });

            // Backspace support
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !input.value && idx > 0) {
                    otpInputs[idx - 1].focus();
                }
            });

            // Paste support
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasteData = (e.clipboardData || window.clipboardData).getData('text');
                const cleanCode = pasteData.replace(/[^0-9]/g, '').slice(0, 6);
                if (cleanCode.length > 0) {
                    cleanCode.split('').forEach((char, i) => {
                        if (otpInputs[i]) {
                            otpInputs[i].value = char;
                        }
                    });
                    if (cleanCode.length === 6) {
                        otpInputs[5].focus();
                        checkAndAutoSubmit();
                    } else if (otpInputs[cleanCode.length]) {
                        otpInputs[cleanCode.length].focus();
                    }
                }
            });
        });

        function checkAndAutoSubmit() {
            const code = getOtpCode();
            if (code.length === 6) {
                document.getElementById('otpForm').requestSubmit();
            }
        }

        function getOtpCode() {
            return otpInputs.map(i => i.value).join('');
        }

        

        // STEP 2: Verify OTP Submit
        async function handleOtpSubmit(e) {
            e.preventDefault();
            const code = getOtpCode();
            if (code.length !== 6) {
                showAlert('Please enter the complete 6-digit code.');
                return;
            }

            const submitBtn = document.getElementById('otpSubmitBtn');
            const btnText = document.getElementById('otpBtnText');
            const btnSpinner = document.getElementById('otpSpinner');

            submitBtn.disabled = true;
            btnSpinner.style.display = 'inline-block';
            btnText.textContent = 'Verifying code...';
            hideAlert();

            try {
                const response = await API.post('handler.php', {
                    action: 'verify_otp',
                    otp_code: code
                });

                if (response.success) {
                    btnText.textContent = 'Verified!';
                    showAlert('Verification successful! Welcome back.', true);
                    setTimeout(() => {
                        window.location.href = response.redirect || '<?= BASE_URL ?>/modules/dashboard/';
                    }, 500);
                } else {
                    showAlert(response.message || 'Invalid or expired code.');
                    submitBtn.disabled = false;
                    btnSpinner.style.display = 'none';
                    btnText.textContent = 'Verify & Continue';
                    otpInputs.forEach(i => i.value = '');
                    otpInputs[0].focus();
                }
            } catch (err) {
                console.error(err);
                showAlert('Network error. Please try again.');
                submitBtn.disabled = false;
                btnSpinner.style.display = 'none';
                btnText.textContent = 'Verify & Continue';
            }
        }

        // Resend Timer Countdown
        function startResendCountdown(seconds) {
            countdownSeconds = seconds;
            const timerText = document.getElementById('timerText');
            const countdownSpan = document.getElementById('countdownSpan');
            const btnResend = document.getElementById('btnResendOtp');

            timerText.style.display = 'inline';
            btnResend.style.display = 'none';
            countdownSpan.textContent = countdownSeconds;

            if (resendTimer) clearInterval(resendTimer);

            resendTimer = setInterval(() => {
                countdownSeconds--;
                countdownSpan.textContent = countdownSeconds;
                if (countdownSeconds <= 0) {
                    clearInterval(resendTimer);
                    timerText.style.display = 'none';
                    btnResend.style.display = 'inline';
                }
            }, 1000);
        }

        // Resend OTP Action
        async function handleResendOtp() {
            const btnResend = document.getElementById('btnResendOtp');
            btnResend.disabled = true;
            btnResend.textContent = 'Sending...';

            try {
                const response = await API.post('handler.php', { action: 'resend_otp' });
                if (response.success) {
                    showAlert('A fresh verification code has been dispatched.', true);
                    
                    startResendCountdown(60);
                } else {
                    showAlert(response.message || 'Could not resend code. Please wait.');
                }
            } catch (err) {
                showAlert('Network error while requesting new code.');
            } finally {
                btnResend.disabled = false;
                btnResend.textContent = 'Resend Code';
            }
        }
    </script>
</body>
</html>
