<?php
/**
 * System Mailer & Notification Service
 * Small Business Sales and Expense Tracking System
 * 
 * Provides unified email dispatch for OTP codes, welcome notifications,
 * and security alerts with a built-in local development sandbox fallback.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

if (!function_exists('send_system_email')) {
    /**
     * Send an HTML email with text fallback
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject line
     * @param string $htmlBody Full HTML message body
     * @param string $plainText Optional plain-text fallback
     * @return array ['success' => bool, 'message' => string, 'mode' => string]
     */
    function send_system_email(string $to, string $subject, string $htmlBody, string $plainText = ''): array
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Invalid recipient email address.',
                'mode' => 'invalid_recipient'
            ];
        }

        $fromName = APP_NAME . ' Security';
        $fromEmail = 'no-reply@prestigeapple.ph';

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($fromName), $fromEmail),
            'Reply-To: support@prestigeapple.ph',
            'X-Mailer: PHP/' . phpversion()
        ];

        // Log all outbound emails to local sandbox log
        $logsDir = __DIR__ . '/../logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }
        $logFile = $logsDir . '/mail_sandbox.log';

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = str_repeat('=', 80) . PHP_EOL;
        $logEntry .= "[{$timestamp}] DISPATCH TO: {$to}" . PHP_EOL;
        $logEntry .= "SUBJECT: {$subject}" . PHP_EOL;
        $logEntry .= "BODY PREVIEW: " . strip_tags(substr($htmlBody, 0, 300)) . "..." . PHP_EOL;
        $logEntry .= str_repeat('=', 80) . PHP_EOL . PHP_EOL;
        @file_put_contents($logFile, $logEntry, FILE_APPEND);

        // 1. Check if custom SMTP configuration is enabled (prefer local non-versioned config)
        $smtpConfigPath = file_exists(__DIR__ . '/../config/smtp.local.php')
            ? __DIR__ . '/../config/smtp.local.php'
            : __DIR__ . '/../config/smtp.php';
        if (file_exists($smtpConfigPath)) {
            $smtpConfig = require $smtpConfigPath;
            if (is_array($smtpConfig) && !empty($smtpConfig['enabled']) && !empty($smtpConfig['username'])) {
                $smtpResult = send_smtp_socket_email($smtpConfig, $to, $subject, $htmlBody);
                if ($smtpResult['success']) {
                    return [
                        'success' => true,
                        'message' => 'Email delivered successfully to ' . $to . ' via SMTP.',
                        'mode' => 'smtp_live'
                    ];
                }
                error_log('[SMTP Dispatch Notice] SMTP failed: ' . $smtpResult['message']);
                return [
                    'success' => false,
                    'message' => 'SMTP dispatch error: ' . $smtpResult['message'],
                    'mode' => 'smtp_error'
                ];
            }
        }

        // Check if running in local development environment (XAMPP / localhost)
        $isLocalXampp = empty($_SERVER['HTTP_HOST']) ||
            in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true) ||
            in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true) ||
            str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost') ||
            (PHP_OS_FAMILY === 'Windows' && ini_get('SMTP') === 'localhost');

        // In local development, record to sandbox log and avoid attempting closed port 25
        if ($isLocalXampp) {
            return [
                'success' => true,
                'message' => 'Email recorded to local development mail sandbox.',
                'mode' => 'sandbox_log',
                'log' => 'logs/mail_sandbox.log'
            ];
        }

        // In production or configured server, attempt PHP native mail()
        $mailSent = false;
        try {
            $mailSent = @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
        } catch (\Throwable $e) {
            $mailSent = false;
        }

        if ($mailSent) {
            return [
                'success' => true,
                'message' => 'Email successfully dispatched via mail transport.',
                'mode' => 'smtp_mail'
            ];
        }

        return [
            'success' => true,
            'message' => 'Email recorded to local development mail sandbox.',
            'mode' => 'sandbox_log',
            'log' => 'logs/mail_sandbox.log'
        ];
    }
}

if (!function_exists('send_smtp_socket_email')) {
    /**
     * Standalone direct socket-based SMTP client with SSL/TLS support
     * Works without external libraries (e.g. PHPMailer).
     */
    function send_smtp_socket_email(array $smtp, string $to, string $subject, string $htmlBody): array
    {
        $host = $smtp['host'] ?? 'smtp.gmail.com';
        $port = (int) ($smtp['port'] ?? 587);
        $secure = strtolower((string) ($smtp['secure'] ?? 'tls'));
        $username = trim((string) ($smtp['username'] ?? ''));
        // Strip any accidental spaces if the user copied a 4x4 Google App Password (e.g. "abcd efgh ijkl mnop")
        $password = str_replace(' ', '', (string) ($smtp['password'] ?? ''));

        // Gmail requires sender address to match or alias the authenticated Google account
        $fromEmail = !empty($smtp['from_email']) ? trim($smtp['from_email']) : $username;
        if (empty($fromEmail) || str_contains($fromEmail, 'prestigeapple.ph') || str_contains($fromEmail, 'localhost')) {
            $fromEmail = $username;
        }

        $activeStoreName = $_SESSION['store_name'] ?? (defined('APP_NAME') ? APP_NAME : 'Store Panel');
        $fromName = !empty($smtp['from_name']) ? trim($smtp['from_name']) : $activeStoreName;

        if (empty($username) || empty($password)) {
            return ['success' => false, 'message' => 'SMTP credentials not configured. Please supply Gmail address and App Password.'];
        }

        $timeout = 12;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $remote = ($secure === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            return ['success' => false, 'message' => "Could not connect to SMTP server ({$host}:{$port}): {$errstr}"];
        }

        stream_set_timeout($socket, $timeout);

        $read = function () use ($socket) {
            $data = '';
            while ($str = fgets($socket, 515)) {
                $data .= $str;
                if (substr($str, 3, 1) === ' ')
                    break;
            }
            return $data;
        };

        $write = function (string $cmd) use ($socket) {
            fputs($socket, $cmd . "\r\n");
        };

        $res = $read();
        if (!str_starts_with($res, '220')) {
            fclose($socket);
            return ['success' => false, 'message' => 'SMTP greeting failed: ' . trim($res)];
        }

        $write('EHLO localhost');
        $res = $read();

        if ($secure === 'tls' && $port === 587) {
            $write('STARTTLS');
            $res = $read();
            if (!str_starts_with($res, '220')) {
                fclose($socket);
                return ['success' => false, 'message' => 'STARTTLS failed: ' . trim($res)];
            }
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') && defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                fclose($socket);
                return ['success' => false, 'message' => 'TLS crypto handshake failed.'];
            }
            $write('EHLO localhost');
            $res = $read();
        }

        $write('AUTH LOGIN');
        $res = $read();
        if (!str_starts_with($res, '334')) {
            fclose($socket);
            return ['success' => false, 'message' => 'AUTH LOGIN rejected: ' . trim($res)];
        }

        $write(base64_encode($username));
        $res = $read();
        if (!str_starts_with($res, '334')) {
            fclose($socket);
            return ['success' => false, 'message' => 'Username rejected: ' . trim($res)];
        }

        $write(base64_encode($password));
        $res = $read();
        if (!str_starts_with($res, '235')) {
            fclose($socket);
            return ['success' => false, 'message' => 'Gmail authentication failed (535): Invalid App Password or Username. Details: ' . trim($res)];
        }

        $write("MAIL FROM: <{$fromEmail}>");
        $res = $read();
        if (!str_starts_with($res, '250')) {
            fclose($socket);
            return ['success' => false, 'message' => 'MAIL FROM rejected: ' . trim($res)];
        }

        $write("RCPT TO: <{$to}>");
        $res = $read();
        if (!str_starts_with($res, '250')) {
            fclose($socket);
            return ['success' => false, 'message' => 'RCPT TO rejected: ' . trim($res)];
        }

        $write('DATA');
        $res = $read();
        if (!str_starts_with($res, '354')) {
            fclose($socket);
            return ['success' => false, 'message' => 'DATA command rejected: ' . trim($res)];
        }

        $mimeHeaders = [
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>",
            "To: <{$to}>",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Date: " . date('r'),
            "X-Mailer: Small Business Tracking System"
        ];

        $safeBody = preg_replace('/^\./m', '..', $htmlBody);
        $emailData = implode("\r\n", $mimeHeaders) . "\r\n\r\n" . $safeBody . "\r\n.";
        $write($emailData);
        $res = $read();

        $write('QUIT');
        fclose($socket);

        if (str_starts_with($res, '250')) {
            return ['success' => true, 'message' => 'Email delivered successfully via SMTP.'];
        }

        return ['success' => false, 'message' => 'Delivery failed: ' . trim($res)];
    }
}

if (!function_exists('send_otp_email')) {
    /**
     * Dispatch 6-digit Login OTP Email
     */
    function send_otp_email(string $toEmail, string $recipientName, string $otpCode, int $expiryMinutes = 10): array
    {
        $activeStore = $_SESSION['store_name'] ?? (defined('APP_NAME') ? APP_NAME : 'Store Operations');
        $subject = "Your {$otpCode} Verification Code - {$activeStore}";

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Code</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0f172a;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
                    
                    <!-- Header -->
                    <tr>
                        <td align="center" style="padding: 32px 24px 20px 24px; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
                            <div style="font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: -0.02em;">
                                {$activeStore}
                            </div>
                            <div style="font-size: 13px; color: #94a3b8; margin-top: 4px;">
                                Operations & Security Verification
                            </div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 32px 32px 24px 32px;">
                            <h2 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 600; color: #0f172a;">
                                Sign-in Verification Code
                            </h2>
                            <p style="margin: 0 0 24px 0; font-size: 14.5px; line-height: 1.6; color: #475569;">
                                Hello <strong>{$recipientName}</strong>,<br>
                                We received a request to access your account. Please use the verification code below to complete your verification:
                            </p>

                            <!-- Big OTP Code Box -->
                            <div style="background-color: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 20px; text-align: center; margin: 24px 0;">
                                <span style="font-family: ui-monospace, Menlo, Monaco, Consolas, monospace; font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #2160cc;">
                                    {$otpCode}
                                </span>
                                <div style="font-size: 12.5px; color: #64748b; margin-top: 8px;">
                                    Valid for the next <strong>{$expiryMinutes} minutes</strong>
                                </div>
                            </div>

                            <p style="margin: 0 0 16px 0; font-size: 13.5px; line-height: 1.5; color: #64748b;">
                                If you did not request this verification code, please ignore this email or notify your system administrator immediately. Do not share this code with anyone.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; text-align: center;">
                            &copy; 2026 {$activeStore}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        return send_system_email($toEmail, $subject, $html);
    }
}

if (!function_exists('send_login_welcome_email')) {
    /**
     * Dispatch Successful Sign-In Welcome & Security Alert Email
     */
    function send_login_welcome_email(string $toEmail, string $recipientName, string $roleLabel, array $meta = []): array
    {
        $activeStore = $_SESSION['store_name'] ?? (defined('APP_NAME') ? APP_NAME : 'Store Operations');
        $subject = "Welcome Back - Successful Sign-In to " . $activeStore;

        $loginTime = $meta['time'] ?? date('F j, Y - g:i:s A (T)');
        $ipAddress = $meta['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $deviceInfo = $meta['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Web Browser');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome Back Notification</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0f172a;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
                    
                    <!-- Header Banner -->
                    <tr>
                        <td align="center" style="padding: 32px 24px 24px 24px; background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                            <div style="font-size: 32px; margin-bottom: 8px;">🎉</div>
                            <div style="font-size: 22px; font-weight: 700; color: #ffffff; letter-spacing: -0.02em;">
                                Welcome Back, {$recipientName}!
                            </div>
                            <div style="font-size: 13.5px; color: #d1fae5; margin-top: 4px;">
                                Role: {$roleLabel} &bull; {$activeStore}
                            </div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 32px 32px 24px 32px;">
                            <p style="margin: 0 0 20px 0; font-size: 14.5px; line-height: 1.6; color: #334155;">
                                You have successfully authenticated and signed in to <strong>{$activeStore}</strong>.
                            </p>

                            <!-- Sign-in Details Table -->
                            <table border="0" cellpadding="8" cellspacing="0" width="100%" style="background-color: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; font-size: 13px; color: #475569; margin-bottom: 24px;">
                                <tr>
                                    <td width="35%" style="font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0;">Sign-in Time:</td>
                                    <td style="border-bottom: 1px solid #e2e8f0;">{$loginTime}</td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0;">IP Address:</td>
                                    <td style="border-bottom: 1px solid #e2e8f0;"><code>{$ipAddress}</code></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600; color: #1e293b;">Device / Agent:</td>
                                    <td style="word-break: break-all;">{$deviceInfo}</td>
                                </tr>
                            </table>

                            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #64748b;">
                                🔒 <strong>Security Note:</strong> If this login wasn't initiated by you, an unauthorized party may have access to your credentials. Please contact your system administrator immediately to reset your password and terminate active sessions.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; text-align: center;">
                            &copy; 2026 {$activeStore} &bull; Security Operations Center
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        return send_system_email($toEmail, $subject, $html);
    }
}
