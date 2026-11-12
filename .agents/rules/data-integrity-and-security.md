---
trigger: always_on
description: Mandatory security, financial data integrity, and transaction invariants.
---

# Data Integrity & Security Rules

All agents operating in this codebase MUST strictly adhere to the following rules without exception.

---

## 1. Financial & Transaction Immutability
- **No Hard Deletes**: Never execute a `DELETE` query against `sales`, `expenses`, `orders`, `invoices`, or `payments`.
- **Soft Deletion Protocol**: Any cancellation or voiding of a transaction must use `deleted_at = NOW()` and `void_reason`, retaining full historical data.
- **Audit Trails**: Every mutation (`INSERT`, `UPDATE`, `SOFT_DELETE`) to financial tables must create an entry in `audit_logs` tracking `user_id`, `action`, `old_values`, and `new_values`.

---

## 2. Inventory & Stock Mutation Atomicity
- **Mandatory Transactions**: Any action that modifies both an order/sale and inventory levels must be wrapped in a database transaction (`$pdo->beginTransaction()`, `$pdo->commit()`, `$pdo->rollBack()`).
- **No Negative Stock**: An order must verify sufficient stock before deduction, unless backorders are explicitly flagged.
- **Stock Movement Log**: Every change in quantity must write a corresponding movement record in `inventory_movements` (e.g. `SALE`, `RESTOCK`, `ADJUSTMENT`, `RETURN`).

---

## 3. Database Security & Prepared Statements
- **Zero SQL Concatenation**: Direct variable interpolation into SQL strings (e.g. `"SELECT * FROM users WHERE id = $id"`) is strictly forbidden.
- **PDO Parameter Binding**: All dynamic inputs must be passed through parameterized PDO prepared statements (`$stmt->prepare()` and `$stmt->execute([':id' => $id])`).
- **Strict Data Types**: Monetary values must be stored as `DECIMAL(12,2)` (never `FLOAT` or `DOUBLE`).

---

## 4. Input Sanitization & Output Escaping
- **XSS Prevention**: Never output raw user data to the browser. Always pass through:
  ```php
  htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
  ```
- **Form Protection**: Every state-changing form (`POST`/`PUT`/`DELETE`) must require a validated CSRF token stored in `$_SESSION['csrf_token']`.

---

## 5. RBAC & Authentication Enforcement
- **Server-Side Verification**: Permissions must never be checked via client-side inputs or cookies. All checks must evaluate `$_SESSION['user_id']` and `$_SESSION['role_id']`.
- **Guard Pattern**: Every module script or API endpoint must initiate with session checks and permission validation before executing any business logic:
  ```php
  require_once __DIR__ . '/../../includes/auth.php';
  require_once __DIR__ . '/../../includes/rbac.php';
  check_permission('sales_create');
  ```
