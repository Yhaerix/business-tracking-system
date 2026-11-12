---
trigger: always_on
description: Mandatory application security requirements, authentication standards, and defensive coding rules.
---

# Security Requirements & Defensive Rules

All code written by agents must strictly satisfy these security requirements to prevent unauthorized access, data compromise, and common web vulnerabilities.

---

## 1. Authentication & Password Security
- **Hashing Standard**: Passwords must always be hashed using `password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])`.
- **Zero Plaintext**: Plaintext passwords must never be stored in the database, logged in debug/audit logs, or transmitted via GET query parameters.
- **Verification**: Always verify credentials using `password_verify($input_password, $hashed_password)`. Never implement custom hashing (no MD5, SHA1, or unsalted SHA256).
- **Brute Force Defense**: Track consecutive failed login attempts per account/IP. Lock out further attempts after 5 failures for 15 minutes.

---

## 2. Session Management & Defense
- **Session ID Regeneration**: Always invoke `session_regenerate_id(true)` immediately upon successful user login to prevent session fixation attacks.
- **Secure Cookie Configuration**: Sessions must be initialized with secure cookie parameters:
  ```php
  session_set_cookie_params([
      'lifetime' => 1800, // 30 minutes
      'path' => '/',
      'httponly' => true,
      'samesite' => 'Strict'
  ]);
  ```
- **Idle Timeout**: Invalidate and destroy sessions after 30 minutes of inactivity.
- **Safe Logout**: Full session teardown must wipe `$_SESSION = []`, expire the session cookie, and call `session_destroy()`.

---

## 3. Role-Based Access Control (RBAC) Enforcement
- **Server-Side Authorization**: Every module endpoint must verify both authentication (`$_SESSION['user_id']`) and role privileges (`check_permission($required_permission)`).
- **Principle of Least Privilege**:
  - `Admin`: Full access to settings, user management, audit logs, and reports.
  - `Manager`: Access to sales, expenses, stock, reports, and COD verification. Cannot manage users or purge records.
  - `Cashier`: Access to create sales, record COD orders, and view basic inventory only. Cannot view net income, edit past expenses, or alter roles.
- **No Client-Side Reliance**: Never trust roles, user IDs, or permissions passed from frontend forms, hidden inputs, or query strings.

---

## 4. Injection, CSRF, and XSS Defenses
- **SQL Injection**: All dynamic queries must use PDO prepared statements. Concatenating variables into SQL strings is forbidden.
- **CSRF Token Validation**: Every state-altering HTTP request (`POST`, `PUT`, `DELETE`) must validate a cryptographically secure CSRF token stored in `$_SESSION['csrf_token']` using `hash_equals()`.
- **XSS Output Sanitization**: Any dynamic content rendered in HTML views must be escaped with `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')`.

---

## 5. Information Disclosure & Error Shielding
- **Production Error Handling**: Database connection strings, SQL errors, and system stack traces must never be printed or returned to the client browser.
- **Safe Client Responses**: If an exception occurs, catch it, write details to a secure internal log, and return a generic error message (e.g., `{"success": false, "message": "An error occurred while processing your request."}`).

---

## 6. Document & Receipt Upload Security
- **Allowed Formats Only**: For expense receipts and invoice attachments, only `.pdf`, `.jpg`, `.jpeg`, and `.png` are permitted.
- **Server-Side MIME Verification**: Always verify the MIME type using `finfo_file()` on the server, not just the client-provided file extension.
- **Execution Prevention**: Store uploads in a dedicated `/uploads` directory with script execution disabled (e.g. `.htaccess` containing `php_flag engine off` or direct web server restriction). Never execute uploaded files.

---

## 7. Security Event Audit Logging
The following security-critical events must always write an entry to `audit_logs`:
- Successful and failed login attempts.
- Password changes and account lockouts.
- Role/permission changes.
- Unauthorized access attempts (403 Forbidden trigger).
