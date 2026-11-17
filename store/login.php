<?php
/**
 * Public Customer Authentication Gateway (Sign In / Register)
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 * Supports ?redirect=checkout.php&reason=auth_required
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If customer is already authenticated, forward them to the target redirect URL
$redirect = trim((string)($_GET['redirect'] ?? ''));
if ($redirect === '' || str_starts_with($redirect, 'http://') || str_starts_with($redirect, 'https://')) {
    $redirect = 'index.php';
}

if (!empty($_SESSION['customer_id'])) {
    header("Location: {$redirect}");
    exit;
}

$pageTitle = 'Sign In to Prestige Apple Store';
$reason = trim((string)($_GET['reason'] ?? ''));
$isFromCheckout = ($reason === 'auth_required' || stripos($redirect, 'checkout') !== false);

require_once __DIR__ . '/includes/store_header.php';
?>

<style>
/* Customer OTP Verification Styles */
.customer-otp-container {
    display: none;
    animation: fadeInOtp 0.25s ease;
}
@keyframes fadeInOtp {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}
.customer-otp-badge {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #e0f2fe;
    color: #0284c7;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    box-shadow: 0 4px 14px rgba(2, 132, 199, 0.15);
}
.customer-otp-grid {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin: 20px 0 24px 0;
}
.c-otp-digit {
    width: 48px;
    height: 56px;
    font-size: 24px;
    font-weight: 700;
    text-align: center;
    border: 1.5px solid #cbd5e1;
    border-radius: 12px;
    background: #f8fafc;
    color: #0f172a;
    transition: all 0.15s ease;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
.c-otp-digit:focus {
    outline: none;
    border-color: #0071e3;
    background: #ffffff;
    box-shadow: 0 0 0 3.5px rgba(0, 113, 227, 0.15);
}
.customer-otp-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 18px;
    font-size: 13px;
    color: #64748b;
}
.btn-c-back {
    background: none;
    border: none;
    color: #64748b;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    padding: 0;
    transition: color 0.15s ease;
}
.btn-c-back:hover {
    color: #0f172a;
}
.btn-c-resend {
    background: none;
    border: none;
    color: #0071e3;
    font-weight: 600;
    cursor: pointer;
    font-size: 13px;
    padding: 0;
}
.btn-c-resend:hover {
    text-decoration: underline;
}
.c-dev-badge {
    margin-top: 20px;
    padding: 10px 14px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12.5px;
    color: #475569;
}
.c-btn-fill-dev {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #0071e3;
    cursor: pointer;
    transition: all 0.15s ease;
}
.c-btn-fill-dev:hover {
    background: #eff6ff;
    border-color: #93c5fd;
}

/* Split-Screen Auth Layout Styles */
.auth-split-container {
    max-width: 960px !important;
    padding: 40px 16px 80px 16px !important;
}
.auth-card {
    display: flex !important;
    background: #ffffff;
    border-radius: 28px;
    box-shadow: 0 16px 50px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    padding: 0 !important;
    border: none;
    min-height: 560px;
    transition: flex-direction 0.35s ease;
}
.auth-card.mode-signin {
    flex-direction: row !important;
}
.auth-card.mode-register {
    flex-direction: row-reverse !important;
}
.auth-col-form {
    flex: 1.15;
    padding: 48px 44px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
    background: #ffffff;
}
.auth-col-brand {
    flex: 0.85;
    background: linear-gradient(145deg, #1d1d1f 0%, #000000 100%);
    color: #ffffff;
    padding: 48px 36px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.auth-col-brand::before {
    content: '';
    position: absolute;
    top: -50px;
    right: -50px;
    width: 220px;
    height: 220px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(0, 113, 227, 0.28) 0%, transparent 70%);
    pointer-events: none;
}
.auth-col-brand::after {
    content: '';
    position: absolute;
    bottom: -50px;
    left: -50px;
    width: 200px;
    height: 200px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(245, 158, 11, 0.18) 0%, transparent 70%);
    pointer-events: none;
}
.auth-brand-inner {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    max-width: 320px;
    width: 100%;
}
.auth-brand-logo-wrap {
    width: 76px;
    height: 76px;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    margin-bottom: 20px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
}
.auth-brand-name {
    font-size: 26px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.02em;
    margin: 0 0 8px 0;
}
.auth-brand-name span.accent {
    color: #0071e3;
}
.auth-brand-tagline {
    font-size: 13.5px;
    color: #94a3b8;
    line-height: 1.5;
    margin: 0 0 24px 0;
}
.auth-brand-perks {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
    text-align: left;
}
.auth-perk-item {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 10px 14px;
    font-size: 12.5px;
    color: #e2e8f0;
    font-weight: 500;
}
@media (max-width: 768px) {
    .auth-card.mode-signin,
    .auth-card.mode-register {
        flex-direction: column !important;
    }
    .auth-col-brand {
        padding: 32px 20px;
    }
    .auth-col-form {
        padding: 32px 20px;
    }
    .auth-brand-perks {
        display: none;
    }
}
</style>

<main class="store-main">
    <div class="store-container auth-split-container">
        
        <?php if ($isFromCheckout): ?>
        <!-- Checkout Gatekeeper Alert Banner -->
        <div class="auth-checkout-gate-banner" id="checkoutGateBanner" style="max-width: 960px; margin: 0 auto 20px auto;">
            <div class="gate-icon">🛒</div>
            <div class="gate-content">
                <div class="gate-title">Sign In Required to Checkout</div>
                <div class="gate-text">
                    Your shopping bag has been saved! Please sign in or create an account to finalize your insured delivery details.
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Auth Card Container: Split 2-Column with Alternating Register -->
        <div class="auth-card mode-signin" id="authCard">
            
            <!-- Form Column -->
            <div class="auth-col-form" id="authColForm">
                <!-- Heading -->
                <div style="margin-bottom: 22px;">
                    <h1 class="auth-title" id="authTitle" style="font-size: 26px; text-align: left;">Sign In</h1>
                    <p class="auth-subtitle" id="authSubtitle" style="text-align: left;">
                        Sign in to your account.
                    </p>
                </div>

                <!-- Tab Switcher (Sign In vs Create Account) -->
                <div class="auth-tabs" role="tablist">
                    <button type="button" class="auth-tab-btn active" id="tabBtnSignIn" onclick="switchAuthTab('signin')" role="tab" aria-selected="true">
                        Sign In
                    </button>
                    <button type="button" class="auth-tab-btn" id="tabBtnRegister" onclick="switchAuthTab('register')" role="tab" aria-selected="false">
                        Create Account
                    </button>
                </div>

                <!-- In-Card Error Notice -->
                <div id="authAlertBox" class="auth-alert-box" style="display: none;"></div>

            <!-- ============================================================
                 1. SIGN IN FORM
                 ============================================================ -->
            <form id="signInForm" class="auth-form" onsubmit="handleCustomerSignIn(event)">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">
                
                <div class="auth-form-group">
                    <label class="auth-form-label" for="loginIdentifier">Email or Phone</label>
                    <input 
                        type="text" 
                        id="loginIdentifier" 
                        name="login_identifier" 
                        class="auth-form-input" 
                        placeholder="Email or phone" 
                        required 
                        autocomplete="username"
                    >
                </div>

                <div class="auth-form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label class="auth-form-label" for="loginPassword" style="margin-bottom: 0;">Password</label>
                        <a href="javascript:void(0)" onclick="openForgotPasswordModal()" class="auth-forgot-link">Forgot password?</a>
                    </div>
                    <div class="auth-password-wrapper">
                        <input 
                            type="password" 
                            id="loginPassword" 
                            name="password" 
                            class="auth-form-input" 
                            placeholder="Password" 
                            required 
                            autocomplete="current-password"
                        >
                        <button type="button" class="auth-toggle-pwd" onclick="togglePasswordVisibility('loginPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div style="margin-bottom: 22px;">
                    <label class="auth-checkbox-label">
                        <input type="checkbox" id="rememberMeCheck" checked>
                        <span>Remember me</span>
                    </label>
                </div>

                <button type="submit" id="signInSubmitBtn" class="btn-apple-primary auth-submit-btn">
                    Sign In
                </button>

                <div class="auth-switch-prompt">
                    Don't have an account? 
                    <a href="javascript:void(0)" onclick="switchAuthTab('register')">Sign up</a>
                </div>
            </form>

            <!-- ============================================================
                 2. CREATE ACCOUNT FORM
                 ============================================================ -->
            <form id="registerForm" class="auth-form" style="display: none;" onsubmit="handleCustomerRegister(event)">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">

                <div class="auth-form-group">
                    <label class="auth-form-label" for="regName">Full Name</label>
                    <input 
                        type="text" 
                        id="regName" 
                        name="name" 
                        class="auth-form-input" 
                        placeholder="Full name" 
                        required 
                        minlength="2"
                        autocomplete="name"
                    >
                </div>

                <div class="auth-form-group">
                    <label class="auth-form-label" for="regPhone">Phone Number</label>
                    <input 
                        type="tel" 
                        id="regPhone" 
                        name="phone" 
                        class="auth-form-input" 
                        placeholder="Phone number" 
                        required 
                        minlength="10"
                        maxlength="15"
                        autocomplete="tel"
                    >
                </div>

                <div class="auth-form-group">
                    <label class="auth-form-label" for="regEmail">Email Address</label>
                    <input 
                        type="email" 
                        id="regEmail" 
                        name="email" 
                        class="auth-form-input" 
                        placeholder="Email address" 
                        required 
                        autocomplete="email"
                    >
                </div>

                <div class="auth-form-group">
                    <label class="auth-form-label" for="regPassword">Password</label>
                    <div class="auth-password-wrapper">
                        <input 
                            type="password" 
                            id="regPassword" 
                            name="password" 
                            class="auth-form-input" 
                            placeholder="Password" 
                            required 
                            minlength="8"
                            autocomplete="new-password"
                        >
                        <button type="button" class="auth-toggle-pwd" onclick="togglePasswordVisibility('regPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="auth-form-group">
                    <label class="auth-form-label" for="regConfirmPassword">Confirm Password</label>
                    <div class="auth-password-wrapper">
                        <input 
                            type="password" 
                            id="regConfirmPassword" 
                            name="confirm_password" 
                            class="auth-form-input" 
                            placeholder="Confirm password" 
                            required 
                            minlength="8"
                            autocomplete="new-password"
                        >
                        <button type="button" class="auth-toggle-pwd" onclick="togglePasswordVisibility('regConfirmPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" id="registerSubmitBtn" class="btn-apple-primary auth-submit-btn">
                    Create Account
                </button>

                <div class="auth-switch-prompt">
                    Already have an account? 
                    <a href="javascript:void(0)" onclick="switchAuthTab('signin')">Sign in</a>
                </div>
            </form>

            <!-- ============================================================
                 3. CUSTOMER OTP VERIFICATION SECTION (LOGIN & REGISTRATION)
                 ============================================================ -->
            <div id="customerOtpSection" class="customer-otp-container">
                <div class="customer-otp-badge">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        <circle cx="12" cy="16" r="1"/>
                    </svg>
                </div>

                <div style="text-align: center; margin-bottom: 20px;">
                    <div style="font-size: 17px; font-weight: 700; color: #1d1d1f; margin-bottom: 4px;">
                        Enter Verification Code
                    </div>
                    <div style="font-size: 13px; color: #86868b; line-height: 1.5;">
                        We sent a 6-digit verification code to your registered email:<br>
                        <strong id="customerOtpEmailDisplay" style="color: #0071e3; font-size: 14px;">your registered email</strong>
                        <div style="margin-top: 6px; font-size: 12px; color: #64748b;">
                            Please check your email inbox and enter the 6-digit code below.
                        </div>
                    </div>
                </div>

                <form id="customerOtpForm" onsubmit="submitCustomerOtp(event)">
                    <div class="customer-otp-grid" id="customerOtpGrid">
                        <input type="text" class="c-otp-digit" id="cOtp1" maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" autofocus>
                        <input type="text" class="c-otp-digit" id="cOtp2" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                        <input type="text" class="c-otp-digit" id="cOtp3" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                        <input type="text" class="c-otp-digit" id="cOtp4" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                        <input type="text" class="c-otp-digit" id="cOtp5" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                        <input type="text" class="c-otp-digit" id="cOtp6" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                    </div>

                    <button type="submit" id="customerOtpSubmitBtn" class="btn-apple-primary auth-submit-btn">
                        Verify &amp; Continue
                    </button>
                </form>

                <div class="customer-otp-meta">
                    <button type="button" class="btn-c-back" onclick="backToAuthTabs()">
                        &larr; Back to <span id="backToTabName">sign in</span>
                    </button>
                    <div>
                        <span id="cOtpTimerText">Resend in <strong id="cOtpCountdown">60</strong>s</span>
                        <button type="button" id="cBtnResend" class="btn-c-resend" style="display: none;" onclick="resendCustomerOtp()">
                            Resend Code
                        </button>
                    </div>
                </div>

                <!-- Sandbox Auto-Fill OTP Helper -->
                <div id="customerDevBadge" class="c-dev-badge" style="display: none;">
                    <div>
                        <span>OTP Code:</span> <strong id="customerDevOtpCode" style="font-size: 14.5px; letter-spacing: 1px; color: #0071e3;">------</strong>
                    </div>
                    <button type="button" class="c-btn-fill-dev" onclick="autoFillCustomerDevOtp()">
                        Auto-fill OTP
                    </button>
                </div>
            </div>

            </div><!-- /.auth-col-form -->

            <!-- Right / Brand Column (Logo & Name) -->
            <div class="auth-col-brand" id="authColBrand">
                <div class="auth-brand-inner">
                    <div class="auth-brand-logo-wrap">
                        <svg viewBox="0 0 170 170" width="46" height="46">
                            <path fill="currentColor" d="M150.37 130.25c-2.45 5.66-5.35 10.87-8.71 15.66-4.58 6.53-8.33 11.05-11.22 13.56-4.48 4.12-9.28 6.23-14.42 6.35-3.69 0-8.14-1.05-13.32-3.18-5.19-2.12-9.97-3.17-14.34-3.17-4.58 0-9.49 1.05-14.75 3.17-5.26 2.13-9.5 3.24-12.74 3.35-4.35.13-9.16-1.9-14.42-6.08-3.69-3.04-7.56-7.71-11.61-14.01-6.19-9.56-11.07-20.59-14.65-33.09-3.58-12.5-5.37-24.16-5.37-34.98 0-14.28 3.52-26.07 10.57-35.37 7.05-9.3 15.82-14.07 26.31-14.32 5.08 0 10.42 1.25 16.03 3.75 5.6 2.5 9.42 3.81 11.45 3.93 1.8.13 5.86-1.25 12.18-4.14 6.32-2.89 11.75-4.19 16.29-3.9 11.96.98 21.6 5.37 28.91 13.18-10.47 6.38-15.6 15.22-15.39 26.52.22 8.94 3.75 16.51 10.59 22.71 6.84 6.2 14.85 9.77 24.03 10.71-2.22 6.5-4.88 13.06-7.98 19.68zM119.22 31.84c0-7.2 2.66-13.88 7.98-20.04 5.32-6.16 11.77-9.93 19.35-11.31.25 1.15.38 2.21.38 3.19 0 7.37-2.82 14.26-8.46 20.67-5.64 6.41-12.35 10.23-20.13 11.46-.38-1.31-.57-2.64-.57-3.97z"/>
                        </svg>
                    </div>
                    <h2 class="auth-brand-name">Prestige <span class="accent">Apple</span></h2>
                    <p class="auth-brand-tagline">
                        Official Store Experience. Genuine Apple innovations with insured nationwide shipping &amp; local warranty.
                    </p>
                    <div class="auth-brand-perks">
                        <div class="auth-perk-item">
                            <span>✨</span> 100% Brand New Sealed Devices
                        </div>
                        <div class="auth-perk-item">
                            <span>🛡️</span> 1-Year Official Apple Warranty
                        </div>
                        <div class="auth-perk-item">
                            <span>🚀</span> Insured Nationwide Express Shipping
                        </div>
                        <div class="auth-perk-item">
                            <span>🪙</span> Earn &amp; Redeem Prestige Coins
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ================================================================
             3. MODAL: FORGOT PASSWORD (OTP RESET)
             ================================================================ -->
        <div id="forgotPasswordModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; padding:16px;">
            <div style="background:#ffffff; max-width:440px; width:100%; border-radius:20px; padding:28px; box-shadow:0 20px 50px rgba(0,0,0,0.18); position:relative;">
                <button type="button" onclick="closeForgotPasswordModal()" style="position:absolute; top:18px; right:18px; background:none; border:none; font-size:22px; color:#86868b; cursor:pointer;" aria-label="Close">&times;</button>
                <div style="width:48px; height:48px; border-radius:50%; background:#fef3c7; color:#d97706; display:flex; align-items:center; justify-content:center; font-size:22px; margin-bottom:14px;">
                    🔑
                </div>
                <h3 style="font-size:18px; font-weight:700; color:#1d1d1f; margin-bottom:8px;">Reset Password</h3>

                <!-- Step 1: Request Reset Code -->
                <div id="forgotPwdStep1">
                    <p style="font-size:13.5px; color:#64748b; line-height:1.5; margin-bottom:16px;">
                        Enter your registered email address. We will send a 6-digit verification code to reset your password.
                    </p>
                    <div id="forgotPwdAlert1" style="display:none; padding:10px 14px; border-radius:10px; font-size:13px; margin-bottom:14px;"></div>
                    <form onsubmit="handleRequestPasswordReset(event)">
                        <div class="auth-form-group" style="margin-bottom:16px;">
                            <label class="auth-form-label" for="resetEmail">Email Address</label>
                            <input type="email" id="resetEmail" class="auth-form-input" placeholder="e.g. customer@example.com" required autocomplete="email">
                        </div>
                        <div style="display:flex; gap:10px; justify-content:flex-end;">
                            <button type="button" onclick="closeForgotPasswordModal()" style="height:42px; padding:0 16px; background:#f1f5f9; color:#475569; border:none; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer;">Cancel</button>
                            <button type="submit" id="btnSendResetCode" class="btn-apple-primary" style="height:42px; padding:0 20px; font-size:13px; border-radius:10px; font-weight:600;">Send Reset Code</button>
                        </div>
                    </form>
                </div>

                <!-- Step 2: Verify OTP & Enter New Password -->
                <div id="forgotPwdStep2" style="display:none;">
                    <p style="font-size:13.5px; color:#64748b; line-height:1.5; margin-bottom:14px;">
                        Enter the 6-digit verification code sent to <strong id="resetTargetEmail">your email</strong> and choose a new password.
                    </p>
                    <div id="forgotPwdAlert2" style="display:none; padding:10px 14px; border-radius:10px; font-size:13px; margin-bottom:14px;"></div>
                    
                    <!-- Dev Quick-Fill Badge -->
                    <div id="resetDevBadge" style="display:none; margin-bottom:14px; padding:8px 12px; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; align-items:center; justify-content:space-between; font-size:12px; color:#475569;">
                        <span>Code: <strong id="resetDevOtpCode" style="color:#0071e3; font-family:monospace; font-size:14px;"></strong></span>
                        <button type="button" onclick="fillResetDevOtp()" style="background:#fff; border:1px solid #cbd5e1; border-radius:4px; padding:3px 8px; font-size:11px; font-weight:600; color:#0071e3; cursor:pointer;">Auto Fill</button>
                    </div>

                    <form onsubmit="handleExecutePasswordReset(event)">
                        <div class="auth-form-group" style="margin-bottom:12px;">
                            <label class="auth-form-label" for="resetOtpCode">6-Digit Code</label>
                            <input type="text" id="resetOtpCode" class="auth-form-input" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required style="font-family:monospace; letter-spacing:4px; text-align:center; font-size:16px; font-weight:700;">
                        </div>
                        <div class="auth-form-group" style="margin-bottom:12px;">
                            <label class="auth-form-label" for="resetNewPassword">New Password</label>
                            <input type="password" id="resetNewPassword" class="auth-form-input" placeholder="At least 6 characters" minlength="6" required>
                        </div>
                        <div class="auth-form-group" style="margin-bottom:18px;">
                            <label class="auth-form-label" for="resetConfirmPassword">Confirm New Password</label>
                            <input type="password" id="resetConfirmPassword" class="auth-form-input" placeholder="Re-enter new password" minlength="6" required>
                        </div>
                        <div style="display:flex; gap:10px; justify-content:flex-end;">
                            <button type="button" onclick="backToResetStep1()" style="height:42px; padding:0 14px; background:#f1f5f9; color:#475569; border:none; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer;">Back</button>
                            <button type="submit" id="btnSubmitNewPassword" class="btn-apple-primary" style="height:42px; padding:0 20px; font-size:13px; border-radius:10px; font-weight:600;">Reset Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</main>

<script>
const REDIRECT_TARGET = <?= json_encode($redirect, JSON_UNESCAPED_SLASHES) ?>;
const EYE_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

let currentCustomerDevOtp = '';
let cResendTimer = null;
let activePurpose = 'login';
let currentActiveTab = 'signin';

function switchAuthTab(tab) {
    const tabSignIn = document.getElementById('tabBtnSignIn');
    const tabRegister = document.getElementById('tabBtnRegister');
    const formSignIn = document.getElementById('signInForm');
    const formRegister = document.getElementById('registerForm');
    const alertBox = document.getElementById('authAlertBox');
    const title = document.getElementById('authTitle');
    const subtitle = document.getElementById('authSubtitle');
    const authCard = document.getElementById('authCard');

    alertBox.style.display = 'none';
    currentActiveTab = tab;

    if (tab === 'signin') {
        tabSignIn.classList.add('active');
        tabRegister.classList.remove('active');
        formSignIn.style.display = 'block';
        formRegister.style.display = 'none';
        title.textContent = 'Sign In';
        subtitle.textContent = 'Sign in to your account.';
        if (authCard) {
            authCard.classList.remove('mode-register');
            authCard.classList.add('mode-signin');
        }
    } else {
        tabRegister.classList.add('active');
        tabSignIn.classList.remove('active');
        formRegister.style.display = 'block';
        formSignIn.style.display = 'none';
        title.textContent = 'Create Account';
        subtitle.textContent = 'Enter your details to create an account.';
        if (authCard) {
            authCard.classList.remove('mode-signin');
            authCard.classList.add('mode-register');
        }
    }
}

function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = EYE_OFF_SVG;
    } else {
        input.type = 'password';
        btn.innerHTML = EYE_SVG;
    }
}

function showAuthAlert(msg, type = 'error') {
    const alertBox = document.getElementById('authAlertBox');
    alertBox.style.display = 'block';
    alertBox.className = 'auth-alert-box ' + (type === 'success' ? 'auth-alert-success' : 'auth-alert-danger');
    alertBox.innerHTML = (type === 'success' ? '✓ ' : '⚠️ ') + msg;
}

// =========================================================================
// CUSTOMER SIGN IN HANDLER (Dispatches 6-Digit OTP to registered email)
// =========================================================================
async function handleCustomerSignIn(e) {
    e.preventDefault();
    const alertBox = document.getElementById('authAlertBox');
    alertBox.style.display = 'none';

    const identifier = document.getElementById('loginIdentifier').value.trim();
    const password = document.getElementById('loginPassword').value;
    const submitBtn = document.getElementById('signInSubmitBtn');

    if (!identifier || !password) {
        showAuthAlert('Please enter both your email/phone and password.');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Verifying credentials...';

    try {
        const res = await fetch('handler.php?action=customer_login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                login_identifier: identifier,
                password: password
            })
        });

        const json = await res.json();
        const payload = (json && typeof json.data === 'object' && json.data !== null) ? json.data : {};

        if (json && json.success) {
            const requireOtp = payload.require_otp ?? json.require_otp ?? false;
            if (requireOtp) {
                switchToCustomerOtp(payload, 'login');
            } else {
                showAuthAlert('Signed in successfully! Redirecting...', 'success');
                if (window.showStoreNotification) {
                    window.showStoreNotification(json.message || 'Signed in successfully!', 'success');
                }
                setTimeout(() => {
                    window.location.href = REDIRECT_TARGET;
                }, 600);
            }
        } else {
            showAuthAlert(json?.message || 'Invalid credentials. Please try again.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Sign In →';
        }
    } catch (err) {
        console.error('Sign in error:', err);
        showAuthAlert('Network error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Sign In →';
    }
}

// =========================================================================
// CUSTOMER REGISTER HANDLER (Dispatches 6-Digit OTP to registering email)
// =========================================================================
async function handleCustomerRegister(e) {
    e.preventDefault();
    const alertBox = document.getElementById('authAlertBox');
    alertBox.style.display = 'none';

    const name = document.getElementById('regName').value.trim();
    const phone = document.getElementById('regPhone').value.trim();
    const email = document.getElementById('regEmail').value.trim();
    const password = document.getElementById('regPassword').value;
    const confirmPassword = document.getElementById('regConfirmPassword').value;
    const submitBtn = document.getElementById('registerSubmitBtn');

    if (password !== confirmPassword) {
        showAuthAlert('Passwords do not match. Please re-enter your password.');
        return;
    }

    if (password.length < 8) {
        showAuthAlert('Password must be at least 8 characters long.');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing registration...';

    try {
        const res = await fetch('handler.php?action=customer_register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                name: name,
                phone: phone,
                email: email,
                password: password,
                confirm_password: confirmPassword
            })
        });

        const json = await res.json();
        const payload = (json && typeof json.data === 'object' && json.data !== null) ? json.data : {};

        if (json && json.success) {
            const requireOtp = payload.require_otp ?? json.require_otp ?? false;
            if (requireOtp) {
                switchToCustomerOtp(payload, 'register');
            } else {
                showAuthAlert('Account created successfully! Redirecting...', 'success');
                if (window.showStoreNotification) {
                    window.showStoreNotification(json.message || 'Account created successfully!', 'success');
                }
                setTimeout(() => {
                    window.location.href = REDIRECT_TARGET;
                }, 700);
            }
        } else {
            const errs = json?.errors ? Object.values(json.errors).join(', ') : '';
            showAuthAlert((json?.message || 'Registration failed.') + (errs ? ' (' + errs + ')' : ''));
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Account & Continue →';
        }
    } catch (err) {
        console.error('Registration error:', err);
        showAuthAlert('Network error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Account & Continue →';
    }
}

// =========================================================================
// SWITCH TO CUSTOMER OTP VIEW
// =========================================================================
function switchToCustomerOtp(data, purpose = 'login') {
    activePurpose = purpose;
    document.querySelector('.auth-tabs').style.display = 'none';
    document.getElementById('signInForm').style.display = 'none';
    document.getElementById('registerForm').style.display = 'none';
    document.getElementById('customerOtpSection').style.display = 'block';

    const title = document.getElementById('authTitle');
    const subtitle = document.getElementById('authSubtitle');
    title.textContent = 'Two-Factor Authentication';
    subtitle.textContent = (purpose === 'register') 
        ? 'Verify your email to activate your account.' 
        : 'Verify your identity to sign in to your account.';

    const emailDisplay = document.getElementById('customerOtpEmailDisplay');
    emailDisplay.textContent = data.masked_email || data.email || 'your registered email';

    const backName = document.getElementById('backToTabName');
    backName.textContent = (purpose === 'register') ? 'registration' : 'sign in';

    // Show Auto-fill OTP badge if available
    const devBadge = document.getElementById('customerDevBadge');
    const devOtpDisplay = document.getElementById('customerDevOtpCode');
    const devOtpVal = data.dev_otp || data.otp || '';
    if (devOtpVal) {
        currentCustomerDevOtp = devOtpVal;
        devOtpDisplay.textContent = devOtpVal;
        devBadge.style.display = 'flex';
    } else {
        devBadge.style.display = 'none';
    }

    // Reset digit inputs
    const cInputs = getCustomerOtpInputs();
    cInputs.forEach(inp => inp.value = '');

    // Reset Submit Button
    const submitBtn = document.getElementById('customerOtpSubmitBtn');
    submitBtn.disabled = false;
    submitBtn.textContent = 'Verify & Continue';

    // Start 60s countdown
    startCustomerResendCountdown(60);

    setTimeout(() => {
        cInputs[0]?.focus();
    }, 120);
}

function backToAuthTabs() {
    if (cResendTimer) {
        clearInterval(cResendTimer);
    }
    document.getElementById('customerOtpSection').style.display = 'none';
    document.querySelector('.auth-tabs').style.display = 'flex';
    switchAuthTab(currentActiveTab);

    const signBtn = document.getElementById('signInSubmitBtn');
    signBtn.disabled = false;
    signBtn.textContent = 'Sign In';

    const regBtn = document.getElementById('registerSubmitBtn');
    regBtn.disabled = false;
    regBtn.textContent = 'Create Account';
}

function getCustomerOtpInputs() {
    return [
        document.getElementById('cOtp1'),
        document.getElementById('cOtp2'),
        document.getElementById('cOtp3'),
        document.getElementById('cOtp4'),
        document.getElementById('cOtp5'),
        document.getElementById('cOtp6')
    ];
}

function getCustomerOtpCode() {
    return getCustomerOtpInputs().map(i => i.value).join('');
}

// Auto-advance, backspace, and paste handlers for Customer OTP
const cInputs = getCustomerOtpInputs();
cInputs.forEach((input, idx) => {
    input.addEventListener('input', () => {
        const val = input.value.replace(/[^0-9]/g, '');
        input.value = val ? val[0] : '';
        if (val && idx < 5) {
            cInputs[idx + 1].focus();
        }
        if (getCustomerOtpCode().length === 6) {
            document.getElementById('customerOtpForm').requestSubmit();
        }
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !input.value && idx > 0) {
            cInputs[idx - 1].focus();
        }
    });

    input.addEventListener('paste', (e) => {
        e.preventDefault();
        const pasteData = (e.clipboardData || window.clipboardData).getData('text');
        const cleanCode = pasteData.replace(/[^0-9]/g, '').slice(0, 6);
        if (cleanCode.length > 0) {
            cleanCode.split('').forEach((char, i) => {
                if (cInputs[i]) {
                    cInputs[i].value = char;
                }
            });
            if (cleanCode.length === 6) {
                cInputs[5].focus();
                document.getElementById('customerOtpForm').requestSubmit();
            } else if (cInputs[cleanCode.length]) {
                cInputs[cleanCode.length].focus();
            }
        }
    });
});

function autoFillCustomerDevOtp() {
    if (!currentCustomerDevOtp || currentCustomerDevOtp.length !== 6) return;
    const chars = currentCustomerDevOtp.split('');
    chars.forEach((c, i) => {
        if (cInputs[i]) cInputs[i].value = c;
    });
    cInputs[5].focus();
    document.getElementById('customerOtpForm').requestSubmit();
}

function startCustomerResendCountdown(seconds = 60) {
    if (cResendTimer) clearInterval(cResendTimer);
    let remaining = seconds;
    const timerText = document.getElementById('cOtpTimerText');
    const countdownSpan = document.getElementById('cOtpCountdown');
    const resendBtn = document.getElementById('cBtnResend');

    timerText.style.display = 'inline';
    resendBtn.style.display = 'none';
    countdownSpan.textContent = remaining;

    cResendTimer = setInterval(() => {
        remaining--;
        countdownSpan.textContent = remaining;
        if (remaining <= 0) {
            clearInterval(cResendTimer);
            timerText.style.display = 'none';
            resendBtn.style.display = 'inline';
        }
    }, 1000);
}

// =========================================================================
// SUBMIT CUSTOMER OTP
// =========================================================================
async function submitCustomerOtp(e) {
    e.preventDefault();
    const code = getCustomerOtpCode();
    const submitBtn = document.getElementById('customerOtpSubmitBtn');
    const alertBox = document.getElementById('authAlertBox');
    alertBox.style.display = 'none';

    if (code.length !== 6) {
        showAuthAlert('Please enter all 6 digits of the verification code.');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Verifying security code...';

    try {
        const res = await fetch('handler.php?action=verify_customer_otp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ otp_code: code })
        });

        const data = await res.json();

        if (data && data.success) {
            showAuthAlert(data.message || 'Verified successfully! Redirecting...', 'success');
            if (window.showStoreNotification) {
                window.showStoreNotification(data.message || 'Identity verified!', 'success');
            }
            setTimeout(() => {
                window.location.href = REDIRECT_TARGET;
            }, 700);
        } else {
            showAuthAlert(data?.message || 'Invalid or expired verification code.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Verify & Continue';
            cInputs.forEach(i => i.value = '');
            cInputs[0]?.focus();
        }
    } catch (err) {
        console.error('OTP verification error:', err);
        showAuthAlert('Network error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Verify & Continue';
    }
}

// =========================================================================
// RESEND CUSTOMER OTP
// =========================================================================
async function resendCustomerOtp() {
    const resendBtn = document.getElementById('cBtnResend');
    resendBtn.disabled = true;
    resendBtn.textContent = 'Sending...';

    try {
        const res = await fetch('handler.php?action=resend_customer_otp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });

        const data = await res.json();
        const payload = (data && typeof data.data === 'object' && data.data !== null) ? data.data : {};

        if (data && data.success) {
            showAuthAlert('A fresh 6-digit code has been dispatched to your email.', 'success');
            if (window.showStoreNotification) {
                window.showStoreNotification('New verification code sent to your email!', 'success');
            }
            const newDevOtp = payload.dev_otp || data.dev_otp || '';
            if (newDevOtp) {
                currentCustomerDevOtp = newDevOtp;
                document.getElementById('customerDevOtpCode').textContent = newDevOtp;
                document.getElementById('customerDevBadge').style.display = 'flex';
            }
            startCustomerResendCountdown(60);
        } else {
            showAuthAlert(data?.message || 'Could not resend code. Please try again.');
        }
    } catch (err) {
        console.error('Resend OTP error:', err);
        showAuthAlert('Network error occurred while resending code.');
    } finally {
        resendBtn.disabled = false;
        resendBtn.textContent = 'Resend Code';
    }
}

// =========================================================================
// FORGOT PASSWORD MODAL & RESET HANDLERS
// =========================================================================
let currentResetDevOtp = '';

function openForgotPasswordModal() {
    const modal = document.getElementById('forgotPasswordModal');
    if (modal) {
        modal.style.display = 'flex';
        const currentLoginVal = document.getElementById('loginIdentifier')?.value?.trim();
        const resetInp = document.getElementById('resetEmail');
        if (currentLoginVal && resetInp && currentLoginVal.includes('@')) {
            resetInp.value = currentLoginVal;
        }
        backToResetStep1();
    }
}

function closeForgotPasswordModal() {
    const modal = document.getElementById('forgotPasswordModal');
    if (modal) modal.style.display = 'none';
}

function backToResetStep1() {
    document.getElementById('forgotPwdStep1').style.display = 'block';
    document.getElementById('forgotPwdStep2').style.display = 'none';
    const alert1 = document.getElementById('forgotPwdAlert1');
    if (alert1) alert1.style.display = 'none';
    const alert2 = document.getElementById('forgotPwdAlert2');
    if (alert2) alert2.style.display = 'none';
}

async function handleRequestPasswordReset(e) {
    e.preventDefault();
    const email = document.getElementById('resetEmail')?.value?.trim();
    const btn = document.getElementById('btnSendResetCode');
    const alert1 = document.getElementById('forgotPwdAlert1');

    if (!email) return;

    btn.disabled = true;
    btn.textContent = 'Sending code...';
    alert1.style.display = 'none';

    try {
        const res = await fetch('handler.php?action=customer_forgot_password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ email: email })
        });

        const data = await res.json();
        const payload = (data && typeof data.data === 'object' && data.data !== null) ? data.data : {};

        if (data && data.success) {
            document.getElementById('resetTargetEmail').textContent = payload.masked_email || 'your email';
            document.getElementById('forgotPwdStep1').style.display = 'none';
            document.getElementById('forgotPwdStep2').style.display = 'block';

            if (payload.dev_otp) {
                currentResetDevOtp = payload.dev_otp;
                document.getElementById('resetDevOtpCode').textContent = payload.dev_otp;
                document.getElementById('resetDevBadge').style.display = 'flex';
            }

            const alert2 = document.getElementById('forgotPwdAlert2');
            alert2.style.display = 'block';
            alert2.style.background = '#ecfdf5';
            alert2.style.color = '#065f46';
            alert2.textContent = data.message || 'Verification code sent!';
        } else {
            alert1.style.display = 'block';
            alert1.style.background = '#fef2f2';
            alert1.style.color = '#991b1b';
            alert1.textContent = data?.message || 'Unable to find an account matching that identifier.';
        }
    } catch (err) {
        console.error('Password reset request error:', err);
        alert1.style.display = 'block';
        alert1.style.background = '#fef2f2';
        alert1.style.color = '#991b1b';
        alert1.textContent = 'Network error. Please try again.';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Send Reset Code';
    }
}

function fillResetDevOtp() {
    if (!currentResetDevOtp) return;
    const inp = document.getElementById('resetOtpCode');
    if (inp) {
        inp.value = currentResetDevOtp;
        document.getElementById('resetNewPassword')?.focus();
    }
}

async function handleExecutePasswordReset(e) {
    e.preventDefault();
    const otpCode = document.getElementById('resetOtpCode')?.value?.trim();
    const newPassword = document.getElementById('resetNewPassword')?.value;
    const confirmPassword = document.getElementById('resetConfirmPassword')?.value;
    const btn = document.getElementById('btnSubmitNewPassword');
    const alert2 = document.getElementById('forgotPwdAlert2');

    if (!otpCode || !newPassword || !confirmPassword) return;

    if (newPassword !== confirmPassword) {
        alert2.style.display = 'block';
        alert2.style.background = '#fef2f2';
        alert2.style.color = '#991b1b';
        alert2.textContent = 'Passwords do not match.';
        return;
    }

    if (newPassword.length < 6) {
        alert2.style.display = 'block';
        alert2.style.background = '#fef2f2';
        alert2.style.color = '#991b1b';
        alert2.textContent = 'Password must be at least 6 characters.';
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Resetting password...';
    alert2.style.display = 'none';

    try {
        const res = await fetch('handler.php?action=customer_reset_password_with_otp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                otp_code: otpCode,
                new_password: newPassword,
                confirm_password: confirmPassword
            })
        });

        const data = await res.json();

        if (data && data.success) {
            closeForgotPasswordModal();
            showAuthAlert(data.message || 'Password reset successfully! Please sign in with your new password.', 'success');
            const pwdInp = document.getElementById('loginPassword');
            if (pwdInp) {
                pwdInp.value = newPassword;
                pwdInp.focus();
            }
        } else {
            alert2.style.display = 'block';
            alert2.style.background = '#fef2f2';
            alert2.style.color = '#991b1b';
            alert2.textContent = data?.message || 'Invalid or expired verification code.';
        }
    } catch (err) {
        console.error('Password reset execution error:', err);
        alert2.style.display = 'block';
        alert2.style.background = '#fef2f2';
        alert2.style.color = '#991b1b';
        alert2.textContent = 'Network error. Please try again.';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Reset Password';
    }
}
</script>

<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
