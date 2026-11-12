---
name: security-review
description: Comprehensive security auditing checklist to identify and remediate vulnerabilities before finalizing any code.
---

# Security Review Skill

## 1. When to Use
Invoke this skill **before finalizing any code, opening a review, or marking a feature complete**. This ensures zero security regressions and continuous compliance with OWASP Top 10 standards.

---

## 2. The 7-Point Security Audit Checklist

Review every modified or created file against these 7 mandatory security checks:

### Check 1: SQL Injection Defense
- [ ] Are **all** SQL queries using PDO prepared statements?
- [ ] Is there **zero** string concatenation or variable interpolation in SQL strings (e.g. `WHERE id = $id` or `LIKE '%$query%'`)?
- [ ] Are all parameters bound through `$stmt->execute([':key' => $val])` or `bindValue()`?

### Check 2: Cross-Site Scripting (XSS) Defense
- [ ] Are all dynamic variables rendered inside HTML templates escaped?
  ```php
  // REQUIRED:
  htmlspecialchars($variable, ENT_QUOTES, 'UTF-8');
  ```
- [ ] Are inputs inside HTML attributes (e.g. `value="..."`, `href="..."`) properly quoted and sanitized?
- [ ] Does JSON output use `JSON_UNESCAPED_UNICODE` while escaping dangerous characters when rendered inline?

### Check 3: Cross-Site Request Forgery (CSRF) Defense
- [ ] Does every state-altering request (`POST`, `PUT`, `DELETE`) require a valid CSRF token?
- [ ] Is the CSRF token validated using timing-safe comparison:
  ```php
  hash_equals($_SESSION['csrf_token'], $submittedToken);
  ```
- [ ] Does the frontend Fetch API wrapper (`api.js`) automatically include the CSRF token in request headers?

### Check 4: Authentication & Session Lifecycle
- [ ] Are passwords hashed using `password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])`?
- [ ] Is `session_regenerate_id(true)` invoked immediately upon successful credential validation?
- [ ] Are session cookies configured with `HttpOnly`, `SameSite=Strict`, and `lifetime` limits?
- [ ] Are plaintext passwords completely excluded from log files, URLs, and database records?

### Check 5: Broken Access Control (RBAC)
- [ ] Does every endpoint verify both active authentication (`require_login()`) and specific permission (`require_permission(...)`)?
- [ ] Are user IDs, roles, and privileges read strictly from the server-side `$_SESSION`, never from client-side `$_POST`, `$_GET`, or cookies?
- [ ] Can a Cashier or Manager access an Admin-only route (e.g. user administration or audit logs)? (Must return 403 Forbidden).

### Check 6: File & Upload Security (For Expense Receipts)
- [ ] Is the file extension checked against a strict whitelist (`.pdf`, `.jpg`, `.jpeg`, `.png` only)?
- [ ] Is the real MIME type verified server-side using `finfo_file()` (not relying on the client's `$_FILES['type']`)?
- [ ] Are uploaded files stored in a directory where script execution (PHP execution) is strictly disabled?
- [ ] Is the filename sanitized (e.g., hashed with `bin2hex(random_bytes(16))` + extension) to prevent directory traversal?

### Check 7: Information Disclosure & Error Shielding
- [ ] Are raw database exceptions, stack traces, and SQL error strings shielded from the client browser?
- [ ] Are errors caught and logged internally to server logs or `audit_logs` while returning a safe, generic message to the user?

---

## 3. Audit Verdict & Remediation
- **PASS**: All 7 checks satisfied $\rightarrow$ Proceed to `testing-verification`.
- **FAIL**: Any check fails $\rightarrow$ **Stop immediately**. Remediate the vulnerability and re-run the review before claiming the task is complete.
