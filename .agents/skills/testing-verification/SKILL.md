---
name: testing-verification
description: Systematic verification, test execution, and acceptance criteria validation before declaring any task or phase complete.
---

# Testing & Verification Skill

## 1. When to Use
Invoke this skill **after writing implementation code and before claiming any task, phase, or bug fix is finished**.

---

## 2. Core Principle: Evidence Before Assertions
Never declare that code works, passes, or is fixed based solely on confidence. **You must run the code, execute verification tests, and provide concrete evidence** before concluding.

---

## 3. The 4 Verification Dimensions

### Dimension 1: Domain Logic & Business Rules Verification
Verify against core formulas and state machines defined in `docs/BUSINESS_RULES.md`:
- **Financial Calculations**:
  - Subtotal: $\sum (\text{item.price} \times \text{item.quantity})$.
  - Order Total: $\text{Subtotal} - \text{Discount} + \text{Delivery Fee}$ (with Zero Tax invariant).
  - Net Income: $\text{Realized Gross Sales (Delivered)} - \text{Operating Expenses}$.
  - Ensure uncollected, pending, or cancelled orders are strictly **excluded** from realized revenue.
- **COD State Machine Integrity**:
  - `PENDING` $\rightarrow$ `CONFIRMED`: Atomically deducts catalog stock and logs `SALE` movement.
  - `CONFIRMED` $\rightarrow$ `CANCELLED`: Atomically restores catalog stock and logs `RETURN` movement.
  - `CONFIRMED` $\rightarrow$ `DISPATCHED` $\rightarrow$ `DELIVERED`: Collects courier cash.
  - `DELIVERED` $\rightarrow$ `REMITTED`: Settles unremitted cash into physical store drawer.
- **Inventory Bounds**:
  - Attempting to order quantity $>$ available stock must be rejected with zero negative inventory.

### Dimension 2: Database Integrity & Audit Logging
Verify database state in MySQL/MariaDB:
- Inspect database tables directly after mutations:
  - Foreign key constraints enforced (InnoDB engine).
  - Financial records (orders, sales, expenses, invoices) are **soft deleted** (`deleted_at IS NOT NULL`), never hard deleted.
  - `audit_logs` record created with authentic `user_id`, `action`, `old_values`, and `new_values`.
  - Sensitive credentials (passwords, tokens) are strictly redacted from audit logs.

### Dimension 3: API & Endpoint Verification
Verify backend endpoints (`modules/<domain>/handler.php`):
- **Authentication**: Unauthenticated requests return HTTP 401 or redirect to login.
- **Authorization (RBAC)**: Cashier attempting manager/admin routes returns HTTP 403 Forbidden.
- **Anti-CSRF**: State-changing requests without valid CSRF tokens return HTTP 403/419.
- **Contract Adherence**: Responses conform to `{ "success": bool, "message": str, "data": any, "errors": array }`.

### Dimension 4: Frontend & UI Integration Verification
When integrating designs or updating views (`modules/<domain>/index.php`, `assets/`):
- **PHP Syntax & Template Integrity**:
  - Run linting on all modified PHP files: `php -l modules/<domain>/index.php`.
  - Verify dynamic variables are safely escaped: `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')`.
  - Verify every state-altering form contains `<input type="hidden" name="csrf_token" value="...">`.
- **JavaScript Syntax & Runtime Safety**:
  - Verify that scripts have **zero** multiline string syntax errors (escape literal newlines in alert/toast strings as `\n`).
  - Verify all `onclick` handlers reference defined functions in scope.
  - Confirm zero uncaught exceptions in browser console.
- **Navigation & Layout Consistency**:
  - Confirm sidebar contains exactly 10 canonical links matching `includes/sidebar.php`.
  - Verify mobile hamburger menu and drawer backdrop open/close smoothly.
  - Verify responsive layouts across Mobile (375px), Tablet (768px), and Desktop (1280px+).

### Dimension 5: Printable Documents & Output Verification
- **Thermal 80mm Receipt**: Itemized list, transaction number, cashier, payment breakdown formatted cleanly without margins or layout chrome.
- **A4 Invoice**: Business header, bill-to address, order summary, payment status badge, and print stylesheet rules verified via `@media print`.
- **CSV Exports**: UTF-8 encoded with accurate headers and correct column delimiter formatting.

---

## 4. Test Suite Execution (`tests/`)

Always run the full automated test suite to ensure zero regressions:
```bash
php tests/run_tests.php
```

Directory structure:
```text
tests/
├── Unit/                    # Pure PHP calculation & logic tests
│   └── FinancialMathTest.php
├── Integration/             # Database transaction & rollback tests
│   ├── Phase2_Verification_Test.php
│   ├── Phase3_Verification_Test.php
│   ├── Phase4_Verification_Test.php
│   ├── Phase5_Verification_Test.php
│   ├── Phase6_Verification_Test.php
│   └── Phase7_Verification_Test.php
└── run_tests.php            # CLI test runner
```

---

## 5. Verification Output Protocol
When concluding verification, present the evidence explicitly:
```text
[Verification Report]
- Automated Test Suite: 73 / 73 PASS
- PHP Syntax Linting: 0 syntax errors across all views
- JavaScript Syntax Validation: 100% syntactically valid, 0 console errors
- Security Checks: CSRF token verified, XSS escaping confirmed
- Visual & Responsive: Layout verified at desktop and mobile breakpoints
- Verdict: Acceptance criteria fully satisfied. Zero regressions detected.
```
