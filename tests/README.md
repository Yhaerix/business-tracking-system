# Test Harness & Verification Suite

## 1. Overview & Purpose
This testing harness provides automated verification for the **Small Business Sales and Expense Tracking System**. It allows agents (`qa-tester`) and developers to execute unit and integration tests directly from the command line without requiring external heavyweight testing frameworks.

---

## 2. Directory Structure
```text
tests/
├── README.md                # Testing documentation and conventions
├── run_tests.php            # Lightweight CLI test runner (zero external dependencies)
├── Unit/                    # Unit tests for domain logic & math
│   └── FinancialMathTest.php# Net income, order totals, and discount math tests
└── Integration/             # Database transaction, rollback & API tests
```

---

## 3. How to Run Tests
From the root directory (`c:\xampp\htdocs\Business Tracking System`):

```bash
php tests/run_tests.php
```

### Exit Codes:
- **`0`**: All tests passed successfully.
- **`1`**: One or more assertions failed.

---

## 4. Writing New Tests
1. Create a class inside `tests/Unit/` or `tests/Integration/` named `<Feature>Test.php`.
2. Define test methods prefixed with `test_`.
3. Use the built-in assertion helper methods:
   - `assert_true($condition, $message)`
   - `assert_false($condition, $message)`
   - `assert_equals($expected, $actual, $message)`
   - `assert_not_equals($expected, $actual, $message)`
