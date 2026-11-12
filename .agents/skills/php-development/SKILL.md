---
name: php-development
description: Standards, coding conventions, and security patterns for writing clean, modular PHP 8.x backend code with PDO and Vanilla JS integration.
---

# PHP Development Skill

## 1. When to Use
Invoke this skill whenever writing, refactoring, or reviewing server-side PHP scripts, module handlers, API controllers, or database services.

---

## 2. Core Language & Architecture Standards

### 2.1. Environment & Version
- Target runtime: **PHP 8.x** on Apache/XAMPP.
- Use strict comparison operators (`===`, `!==`).
- Declare strict types where appropriate (`declare(strict_types=1);`).
- Do not use deprecated functions (e.g. no `mysql_*`, no `create_function()`).

### 2.2. Separation of Concerns
- **No Direct SQL in HTML Views**: All queries must reside in module handlers or service functions.
- Files named `index.php` in a module are dedicated to **view rendering** (UI layout + passing data).
- Files named `handler.php` or `api.php` in a module are dedicated to **request processing** (validating input, calling database, returning JSON).

---

## 3. Database Access Patterns with PDO

### 3.1. Parameterized Prepared Statements Only
Never interpolate or concatenate variables into SQL strings.

```php
// CORRECT:
$stmt = $pdo->prepare("
    SELECT id, name, selling_price, current_stock 
    FROM products 
    WHERE category_id = :cat_id AND status = 'active'
");
$stmt->execute([':cat_id' => $categoryId]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// FORBIDDEN:
// $stmt = $pdo->query("SELECT * FROM products WHERE category_id = $categoryId");
```

### 3.2. Mandatory Transactions for Multi-Step Mutations
Wrap any mutation that affects more than one table (or multiple rows that must succeed together, e.g. creating an order and deducting stock) inside a PDO transaction:

```php
try {
    $pdo->beginTransaction();

    // 1. Create order
    $stmtOrder = $pdo->prepare("INSERT INTO orders (...) VALUES (...)");
    $stmtOrder->execute([...]);
    $orderId = (int)$pdo->lastInsertId();

    // 2. Insert line items & update inventory
    foreach ($items as $item) {
        // Check stock
        $stmtStock = $pdo->prepare("SELECT current_stock FROM products WHERE id = :id FOR UPDATE");
        $stmtStock->execute([':id' => $item['product_id']]);
        $currentStock = (int)$stmtStock->fetchColumn();

        if ($currentStock < $item['quantity']) {
            throw new Exception("Insufficient stock for product ID: " . $item['product_id']);
        }

        // Deduct stock
        $stmtDeduct = $pdo->prepare("UPDATE products SET current_stock = current_stock - :qty WHERE id = :id");
        $stmtDeduct->execute([':qty' => $item['quantity'], ':id' => $item['product_id']]);

        // Record movement
        // Insert order_item
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log error internally and return safe response
    error_log("Order creation failed: " . $e->getMessage());
    return ['success' => false, 'message' => $e->getMessage()];
}
```

---

## 4. Standard API & AJAX Response Pattern

All AJAX handlers communicating with the frontend Fetch API must return JSON using the standard schema:

```php
header('Content-Type: application/json; charset=utf-8');

function json_response(bool $success, string $message, mixed $data = null, array $errors = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
        'errors'  => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Success example:
json_response(true, 'Product created successfully', ['product_id' => $newId], [], 201);

// Failure example:
json_response(false, 'Validation failed', null, ['Price must be greater than zero'], 422);
```

---

## 5. Security & Sanitization Invariants

### 5.1. CSRF Token Guard
Every POST/PUT/DELETE request must validate the CSRF token:
```php
require_once __DIR__ . '/../../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verify_csrf_token($token)) {
        json_response(false, 'Invalid or expired CSRF token', null, [], 403);
    }
}
```

### 5.2. RBAC Route Guard Pattern
Every module handler and view must start with authentication and permission verification:
```php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_login();
require_permission('sales_create');
```

### 5.3. XSS Escaping in Views
Always wrap dynamic variables rendered in HTML templates with `htmlspecialchars`:
```php
<td class="customer-name"><?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
```

### 5.4. Password Security
Always hash with `PASSWORD_BCRYPT` and verify with `password_verify`:
```php
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
if (password_verify($inputPassword, $user['password_hash'])) {
    session_regenerate_id(true);
    // Proceed with login
}
```

---

## 6. Error Handling & Shielding
- Never output raw SQL errors, stack traces, or database connection strings to the client.
- Catch exceptions, log details securely to server logs or `audit_logs`, and return user-friendly messages.
