<?php
/**
 * Expense Management & Categorization Backend Handler
 * Small Business Sales and Expense Tracking System
 *
 * Actions:
 * - list_expenses    (GET/POST, Permission: expenses_view)
 * - get_expense      (GET/POST, Permission: expenses_view)
 * - create_expense   (POST,     Permission: expenses_manage)
 * - update_expense   (POST,     Permission: expenses_manage)
 * - delete_expense   (POST,     Permission: expenses_delete - Admin Only)
 * - list_categories  (GET/POST, Permission: expenses_view)
 * - create_category  (POST,     Permission: expenses_manage)
 * - update_category  (POST,     Permission: expenses_manage)
 * - delete_category  (POST,     Permission: expenses_manage)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

// Helper: Standardized JSON envelope
function send_json(bool $success, string $message, $data = null, array $errors = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
        'errors'  => $errors
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: Extract request payload (supports JSON, FormData, and urlencoded)
function get_request_data(): array
{
    $data = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = $json;
        }
    } else {
        $data = $_POST;
    }
    return array_merge($_GET, $data);
}

// Helper: Enforce Anti-CSRF Token
function require_csrf(array $input): void
{
    $token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verify_csrf_token($token)) {
        send_json(false, 'Security token invalid or expired. Please refresh the page.', null, ['csrf' => 'Invalid CSRF token'], 403);
    }
}

// 1. Session Authentication Guard
require_login();

$input = get_request_data();
$action = trim((string) ($input['action'] ?? ''));
$db = get_db();
$currentUser = current_user();

try {
    switch ($action) {
        // =====================================================================
        // EXPENSE TRANSACTIONS DOMAIN ACTIONS
        // =====================================================================

        case 'list_expenses':
            if (!has_permission('expenses_view')) {
                send_json(false, 'Access denied: Insufficient permissions (expenses_view required)', null, [], 403);
            }

            $search = trim((string) ($input['search'] ?? ''));
            $categoryId = !empty($input['category_id']) ? (int) $input['category_id'] : null;
            $dateFrom = trim((string) ($input['date_from'] ?? ''));
            $dateTo = trim((string) ($input['date_to'] ?? ''));
            $page = max(1, (int) ($input['page'] ?? 1));
            $limit = min(100, max(1, (int) ($input['limit'] ?? 15)));
            $offset = ($page - 1) * $limit;

            $where = ['e.deleted_at IS NULL'];
            $params = [];

            if ($search !== '') {
                $where[] = '(e.title LIKE :s_title OR e.notes LIKE :s_notes)';
                $params[':s_title'] = '%' . $search . '%';
                $params[':s_notes'] = '%' . $search . '%';
            }

            if ($categoryId !== null && $categoryId > 0) {
                $where[] = 'e.category_id = :category_id';
                $params[':category_id'] = $categoryId;
            }

            if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
                $where[] = 'e.expense_date >= :date_from';
                $params[':date_from'] = $dateFrom;
            }

            if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                $where[] = 'e.expense_date <= :date_to';
                $params[':date_to'] = $dateTo;
            }

            $whereSql = implode(' AND ', $where);

            // Total Count for Pagination
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM expenses e WHERE {$whereSql}");
            $stmtCount->execute($params);
            $totalRecords = (int) $stmtCount->fetchColumn();

            // Fetch Paginated Expense Items
            $stmtItems = $db->prepare("
                SELECT e.id, e.category_id, e.user_id, e.title, e.amount, e.expense_date,
                       e.receipt_path, e.notes, e.created_at, e.updated_at,
                       c.name AS category_name,
                       u.full_name AS user_name
                FROM expenses e
                LEFT JOIN expense_categories c ON e.category_id = c.id
                LEFT JOIN users u ON e.user_id = u.id
                WHERE {$whereSql}
                ORDER BY e.expense_date DESC, e.id DESC
                LIMIT :limit OFFSET :offset
            ");

            foreach ($params as $k => $v) {
                $stmtItems->bindValue($k, $v);
            }
            $stmtItems->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmtItems->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmtItems->execute();

            $rawItems = $stmtItems->fetchAll();
            $items = [];
            foreach ($rawItems as $row) {
                $receiptPath = $row['receipt_path'] ?? null;
                $receiptType = null;
                if (!empty($receiptPath)) {
                    $ext = strtolower(pathinfo($receiptPath, PATHINFO_EXTENSION));
                    if ($ext === 'pdf') {
                        $receiptType = 'pdf';
                    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                        $receiptType = 'image';
                    } else {
                        $receiptType = 'file';
                    }
                }

                $items[] = [
                    'id'            => (int) $row['id'],
                    'category_id'   => (int) $row['category_id'],
                    'category_name' => $row['category_name'] ?? 'Uncategorized',
                    'user_id'       => (int) $row['user_id'],
                    'user_name'     => $row['user_name'] ?? 'System',
                    'title'         => $row['title'],
                    'amount'        => (float) $row['amount'],
                    'expense_date'  => $row['expense_date'],
                    'receipt_path'  => $receiptPath,
                    'receipt_type'  => $receiptType,
                    'notes'         => $row['notes'],
                    'created_at'    => $row['created_at'],
                    'updated_at'    => $row['updated_at']
                ];
            }

            // Summary Metrics Calculation
            $stmtMonth = $db->query("
                SELECT COALESCE(SUM(amount), 0.00) 
                FROM expenses 
                WHERE deleted_at IS NULL 
                  AND DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
            ");
            $monthTotal = (float) $stmtMonth->fetchColumn();

            $stmtToday = $db->query("
                SELECT COALESCE(SUM(amount), 0.00) 
                FROM expenses 
                WHERE deleted_at IS NULL 
                  AND expense_date = CURRENT_DATE
            ");
            $todayTotal = (float) $stmtToday->fetchColumn();

            $stmtTop = $db->query("
                SELECT c.name, COALESCE(SUM(e.amount), 0.00) AS total
                FROM expenses e
                JOIN expense_categories c ON e.category_id = c.id
                WHERE e.deleted_at IS NULL 
                  AND DATE_FORMAT(e.expense_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
                GROUP BY c.id, c.name
                ORDER BY total DESC
                LIMIT 1
            ");
            $topCat = $stmtTop->fetch();
            $topCategory = $topCat ? [
                'name'  => $topCat['name'],
                'total' => (float) $topCat['total']
            ] : [
                'name'  => 'None',
                'total' => 0.00
            ];

            $totalPages = $totalRecords > 0 ? (int) ceil($totalRecords / $limit) : 1;

            send_json(true, 'Expenses retrieved successfully', [
                'items' => $items,
                'pagination' => [
                    'total_records' => $totalRecords,
                    'page'          => $page,
                    'limit'         => $limit,
                    'total_pages'   => $totalPages
                ],
                'metrics' => [
                    'month_total'   => $monthTotal,
                    'today_total'   => $todayTotal,
                    'top_category'  => $topCategory,
                    'total_entries' => $totalRecords
                ]
            ]);
            break;

        case 'get_expense':
            if (!has_permission('expenses_view')) {
                send_json(false, 'Access denied: Insufficient permissions (expenses_view required)', null, [], 403);
            }

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid Expense ID is required.', null, ['id' => 'Invalid ID'], 422);
            }

            $stmt = $db->prepare("
                SELECT e.id, e.category_id, e.user_id, e.title, e.amount, e.expense_date,
                       e.receipt_path, e.notes, e.created_at, e.updated_at,
                       c.name AS category_name,
                       u.full_name AS user_name
                FROM expenses e
                LEFT JOIN expense_categories c ON e.category_id = c.id
                LEFT JOIN users u ON e.user_id = u.id
                WHERE e.id = :id AND e.deleted_at IS NULL
            ");
            $stmt->execute([':id' => $id]);
            $expense = $stmt->fetch();

            if (!$expense) {
                send_json(false, 'Expense record not found or has been voided.', null, [], 404);
            }

            $receiptPath = $expense['receipt_path'] ?? null;
            $receiptType = null;
            if (!empty($receiptPath)) {
                $ext = strtolower(pathinfo($receiptPath, PATHINFO_EXTENSION));
                if ($ext === 'pdf') {
                    $receiptType = 'pdf';
                } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $receiptType = 'image';
                } else {
                    $receiptType = 'file';
                }
            }

            $expense['id'] = (int) $expense['id'];
            $expense['category_id'] = (int) $expense['category_id'];
            $expense['user_id'] = (int) $expense['user_id'];
            $expense['amount'] = (float) $expense['amount'];
            $expense['receipt_type'] = $receiptType;

            send_json(true, 'Expense details retrieved successfully', $expense);
            break;

        case 'create_expense':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('expenses_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (expenses_manage required)', null, [], 403);
            }
            require_csrf($input);

            $title = trim((string) ($input['title'] ?? ''));
            $categoryId = (int) ($input['category_id'] ?? 0);
            $amount = (float) ($input['amount'] ?? 0.0);
            $expenseDate = trim((string) ($input['expense_date'] ?? ''));
            $notes = trim((string) ($input['notes'] ?? ''));

            $errors = [];

            if ($title === '') {
                $errors['title'] = 'Expense title is required.';
            } elseif (mb_strlen($title) > 150) {
                $errors['title'] = 'Expense title cannot exceed 150 characters.';
            }

            if ($categoryId <= 0) {
                $errors['category_id'] = 'Valid expense category selection is required.';
            } else {
                $stmtCat = $db->prepare("SELECT id FROM expense_categories WHERE id = :id");
                $stmtCat->execute([':id' => $categoryId]);
                if (!$stmtCat->fetch()) {
                    $errors['category_id'] = 'Selected expense category does not exist.';
                }
            }

            if ($amount <= 0.0) {
                $errors['amount'] = 'Expense amount must be a positive numeric value greater than zero.';
            }

            if ($expenseDate === '') {
                $errors['expense_date'] = 'Expense date is required.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
                $errors['expense_date'] = 'Expense date must follow the YYYY-MM-DD format.';
            } else {
                [$y, $m, $d] = explode('-', $expenseDate);
                if (!checkdate((int) $m, (int) $d, (int) $y)) {
                    $errors['expense_date'] = 'Provided expense date is not a valid calendar date.';
                }
            }

            // Handle Receipt File Upload
            $receiptPath = null;
            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
                    $errors['receipt'] = 'Receipt upload failed with error code: ' . $_FILES['receipt']['error'];
                } else {
                    $maxSize = 5 * 1024 * 1024; // 5MB
                    if ($_FILES['receipt']['size'] > $maxSize) {
                        $errors['receipt'] = 'Receipt file size exceeds maximum limit of 5MB.';
                    } else {
                        $tmpPath = $_FILES['receipt']['tmp_name'];
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $tmpPath);
                        finfo_close($finfo);

                        $mimeMap = [
                            'image/jpeg'      => '.jpg',
                            'image/png'       => '.png',
                            'application/pdf' => '.pdf'
                        ];

                        if (!array_key_exists($mime, $mimeMap)) {
                            $errors['receipt'] = 'Invalid file type. Only JPG, PNG, and PDF receipts are allowed.';
                        } else {
                            $ext = $mimeMap[$mime];
                            $uploadDir = dirname(__DIR__, 2) . '/uploads/receipts';
                            if (!is_dir($uploadDir)) {
                                @mkdir($uploadDir, 0755, true);
                            }

                            $filename = 'receipt_' . uniqid() . '_' . bin2hex(random_bytes(6)) . $ext;
                            $destination = $uploadDir . '/' . $filename;

                            if (!move_uploaded_file($tmpPath, $destination)) {
                                $errors['receipt'] = 'Failed to move uploaded receipt to destination folder.';
                            } else {
                                $receiptPath = 'uploads/receipts/' . $filename;
                            }
                        }
                    }
                }
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            // Insert Expense Transaction
            $stmtInsert = $db->prepare("
                INSERT INTO expenses (
                    category_id, user_id, title, amount, expense_date,
                    receipt_path, notes, created_at
                ) VALUES (
                    :category_id, :user_id, :title, :amount, :expense_date,
                    :receipt_path, :notes, NOW()
                )
            ");
            $stmtInsert->execute([
                ':category_id'  => $categoryId,
                ':user_id'      => $currentUser['id'],
                ':title'        => $title,
                ':amount'       => number_format($amount, 2, '.', ''),
                ':expense_date' => $expenseDate,
                ':receipt_path' => $receiptPath,
                ':notes'        => $notes !== '' ? $notes : null
            ]);
            $newExpenseId = (int) $db->lastInsertId();

            audit_log('expenses', 'CREATE', $newExpenseId, null, [
                'title'        => $title,
                'category_id'  => $categoryId,
                'amount'       => $amount,
                'expense_date' => $expenseDate,
                'receipt_path' => $receiptPath,
                'notes'        => $notes
            ]);

            send_json(true, 'Expense recorded successfully', [
                'id'           => $newExpenseId,
                'title'        => $title,
                'amount'       => $amount,
                'expense_date' => $expenseDate,
                'receipt_path' => $receiptPath
            ], [], 201);
            break;

        case 'update_expense':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('expenses_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (expenses_manage required)', null, [], 403);
            }
            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid Expense ID is required.', null, ['id' => 'Invalid ID'], 422);
            }

            // Fetch existing active record
            $stmtExisting = $db->prepare("SELECT * FROM expenses WHERE id = :id AND deleted_at IS NULL");
            $stmtExisting->execute([':id' => $id]);
            $existing = $stmtExisting->fetch();
            if (!$existing) {
                send_json(false, 'Expense record not found or has been voided.', null, [], 404);
            }

            $title = trim((string) ($input['title'] ?? ''));
            $categoryId = (int) ($input['category_id'] ?? 0);
            $amount = (float) ($input['amount'] ?? 0.0);
            $expenseDate = trim((string) ($input['expense_date'] ?? ''));
            $notes = trim((string) ($input['notes'] ?? ''));

            $errors = [];

            if ($title === '') {
                $errors['title'] = 'Expense title is required.';
            } elseif (mb_strlen($title) > 150) {
                $errors['title'] = 'Expense title cannot exceed 150 characters.';
            }

            if ($categoryId <= 0) {
                $errors['category_id'] = 'Valid expense category selection is required.';
            } else {
                $stmtCat = $db->prepare("SELECT id FROM expense_categories WHERE id = :id");
                $stmtCat->execute([':id' => $categoryId]);
                if (!$stmtCat->fetch()) {
                    $errors['category_id'] = 'Selected expense category does not exist.';
                }
            }

            if ($amount <= 0.0) {
                $errors['amount'] = 'Expense amount must be a positive numeric value greater than zero.';
            }

            if ($expenseDate === '') {
                $errors['expense_date'] = 'Expense date is required.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
                $errors['expense_date'] = 'Expense date must follow the YYYY-MM-DD format.';
            } else {
                [$y, $m, $d] = explode('-', $expenseDate);
                if (!checkdate((int) $m, (int) $d, (int) $y)) {
                    $errors['expense_date'] = 'Provided expense date is not a valid calendar date.';
                }
            }

            // Handle Optional New Receipt File Upload
            $receiptPath = $existing['receipt_path'];
            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
                    $errors['receipt'] = 'Receipt upload failed with error code: ' . $_FILES['receipt']['error'];
                } else {
                    $maxSize = 5 * 1024 * 1024; // 5MB
                    if ($_FILES['receipt']['size'] > $maxSize) {
                        $errors['receipt'] = 'Receipt file size exceeds maximum limit of 5MB.';
                    } else {
                        $tmpPath = $_FILES['receipt']['tmp_name'];
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $tmpPath);
                        finfo_close($finfo);

                        $mimeMap = [
                            'image/jpeg'      => '.jpg',
                            'image/png'       => '.png',
                            'application/pdf' => '.pdf'
                        ];

                        if (!array_key_exists($mime, $mimeMap)) {
                            $errors['receipt'] = 'Invalid file type. Only JPG, PNG, and PDF receipts are allowed.';
                        } else {
                            $ext = $mimeMap[$mime];
                            $uploadDir = dirname(__DIR__, 2) . '/uploads/receipts';
                            if (!is_dir($uploadDir)) {
                                @mkdir($uploadDir, 0755, true);
                            }

                            $filename = 'receipt_' . uniqid() . '_' . bin2hex(random_bytes(6)) . $ext;
                            $destination = $uploadDir . '/' . $filename;

                            if (!move_uploaded_file($tmpPath, $destination)) {
                                $errors['receipt'] = 'Failed to move uploaded receipt to destination folder.';
                            } else {
                                $receiptPath = 'uploads/receipts/' . $filename;
                            }
                        }
                    }
                }
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            // Execute Update
            $stmtUpdate = $db->prepare("
                UPDATE expenses
                SET category_id = :category_id,
                    title = :title,
                    amount = :amount,
                    expense_date = :expense_date,
                    receipt_path = :receipt_path,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL
            ");
            $stmtUpdate->execute([
                ':category_id'  => $categoryId,
                ':title'        => $title,
                ':amount'       => number_format($amount, 2, '.', ''),
                ':expense_date' => $expenseDate,
                ':receipt_path' => $receiptPath,
                ':notes'        => $notes !== '' ? $notes : null,
                ':id'           => $id
            ]);

            audit_log('expenses', 'UPDATE', $id, [
                'title'        => $existing['title'],
                'category_id'  => (int) $existing['category_id'],
                'amount'       => (float) $existing['amount'],
                'expense_date' => $existing['expense_date'],
                'receipt_path' => $existing['receipt_path'],
                'notes'        => $existing['notes']
            ], [
                'title'        => $title,
                'category_id'  => $categoryId,
                'amount'       => $amount,
                'expense_date' => $expenseDate,
                'receipt_path' => $receiptPath,
                'notes'        => $notes
            ]);

            send_json(true, 'Expense updated successfully', [
                'id'           => $id,
                'title'        => $title,
                'amount'       => $amount,
                'expense_date' => $expenseDate,
                'receipt_path' => $receiptPath
            ]);
            break;

        case 'delete_expense':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            // Strict Authorization: Admin only holds 'expenses_delete'
            if (!has_permission('expenses_delete')) {
                send_json(false, 'Access denied: Only Administrators possess permission to void or delete expenses.', null, [], 403);
            }
            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            $reason = trim((string) ($input['reason'] ?? ''));

            if ($id <= 0) {
                send_json(false, 'Valid Expense ID is required.', null, ['id' => 'Invalid ID'], 422);
            }

            if (mb_strlen($reason) < 3) {
                send_json(false, 'A valid deletion reason (at least 3 characters) is required to void an expense.', null, [
                    'reason' => 'Reason must be at least 3 characters long.'
                ], 422);
            } elseif (mb_strlen($reason) > 255) {
                send_json(false, 'Deletion reason cannot exceed 255 characters.', null, [
                    'reason' => 'Reason cannot exceed 255 characters.'
                ], 422);
            }

            $stmtFind = $db->prepare("SELECT * FROM expenses WHERE id = :id AND deleted_at IS NULL");
            $stmtFind->execute([':id' => $id]);
            $existing = $stmtFind->fetch();

            if (!$existing) {
                send_json(false, 'Expense record not found or already voided.', null, [], 404);
            }

            // Financial Immutability Rule: Soft Delete with Deletion Reason
            $stmtSoftDelete = $db->prepare("
                UPDATE expenses
                SET deleted_at = NOW(),
                    deletion_reason = :reason
                WHERE id = :id AND deleted_at IS NULL
            ");
            $stmtSoftDelete->execute([
                ':reason' => $reason,
                ':id'     => $id
            ]);

            audit_log('expenses', 'SOFT_DELETE', $id, $existing, [
                'deleted_at'      => date('Y-m-d H:i:s'),
                'deletion_reason' => $reason
            ]);

            send_json(true, 'Expense record voided successfully', ['id' => $id]);
            break;

        // =====================================================================
        // CATEGORY DOMAIN ACTIONS
        // =====================================================================

        case 'list_categories':
            if (!has_permission('expenses_view')) {
                send_json(false, 'Access denied: Insufficient permissions (expenses_view required)', null, [], 403);
            }

            $stmtCats = $db->query("
                SELECT c.id, c.name, c.description, c.created_at,
                       COUNT(e.id) AS expense_count,
                       COALESCE(SUM(e.amount), 0.00) AS total_spent
                FROM expense_categories c
                LEFT JOIN expenses e ON c.id = e.category_id AND e.deleted_at IS NULL
                GROUP BY c.id, c.name, c.description, c.created_at
                ORDER BY c.name ASC
            ");
            $rawCats = $stmtCats->fetchAll();
            $categories = [];
            foreach ($rawCats as $row) {
                $categories[] = [
                    'id'            => (int) $row['id'],
                    'name'          => $row['name'],
                    'description'   => $row['description'],
                    'expense_count' => (int) $row['expense_count'],
                    'total_spent'   => (float) $row['total_spent'],
                    'created_at'    => $row['created_at']
                ];
            }

            send_json(true, 'Expense categories retrieved successfully', $categories);
            break;

        case 'create_category':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('expenses_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (expenses_manage required)', null, [], 403);
            }
            require_csrf($input);

            $name = trim((string) ($input['name'] ?? ''));
            $description = trim((string) ($input['description'] ?? ''));

            $errors = [];
            if ($name === '') {
                $errors['name'] = 'Category name is required.';
            } elseif (mb_strlen($name) > 100) {
                $errors['name'] = 'Category name cannot exceed 100 characters.';
            } else {
                $stmtDup = $db->prepare("SELECT id FROM expense_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1");
                $stmtDup->execute([':name' => $name]);
                if ($stmtDup->fetch()) {
                    $errors['name'] = 'An expense category with this name already exists.';
                }
            }

            if (mb_strlen($description) > 255) {
                $errors['description'] = 'Description cannot exceed 255 characters.';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            $stmtInsert = $db->prepare("
                INSERT INTO expense_categories (name, description, created_at)
                VALUES (:name, :description, NOW())
            ");
            $stmtInsert->execute([
                ':name'        => $name,
                ':description' => $description !== '' ? $description : null
            ]);
            $catId = (int) $db->lastInsertId();

            audit_log('expenses', 'CREATE_CATEGORY', $catId, null, [
                'name'        => $name,
                'description' => $description
            ]);

            send_json(true, 'Expense category created successfully', [
                'id'          => $catId,
                'name'        => $name,
                'description' => $description
            ], [], 201);
            break;

        case 'update_category':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('expenses_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (expenses_manage required)', null, [], 403);
            }
            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid Category ID is required.', null, ['id' => 'Invalid ID'], 422);
            }

            $stmtCat = $db->prepare("SELECT id, name, description FROM expense_categories WHERE id = :id");
            $stmtCat->execute([':id' => $id]);
            $oldCat = $stmtCat->fetch();
            if (!$oldCat) {
                send_json(false, 'Expense category not found.', null, [], 404);
            }

            $name = trim((string) ($input['name'] ?? ''));
            $description = trim((string) ($input['description'] ?? ''));

            $errors = [];
            if ($name === '') {
                $errors['name'] = 'Category name is required.';
            } elseif (mb_strlen($name) > 100) {
                $errors['name'] = 'Category name cannot exceed 100 characters.';
            } else {
                $stmtDup = $db->prepare("SELECT id FROM expense_categories WHERE LOWER(name) = LOWER(:name) AND id != :id LIMIT 1");
                $stmtDup->execute([':name' => $name, ':id' => $id]);
                if ($stmtDup->fetch()) {
                    $errors['name'] = 'Another expense category with this name already exists.';
                }
            }

            if (mb_strlen($description) > 255) {
                $errors['description'] = 'Description cannot exceed 255 characters.';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            $stmtUpdate = $db->prepare("
                UPDATE expense_categories
                SET name = :name, description = :description
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':name'        => $name,
                ':description' => $description !== '' ? $description : null,
                ':id'          => $id
            ]);

            audit_log('expenses', 'UPDATE_CATEGORY', $id, $oldCat, [
                'name'        => $name,
                'description' => $description
            ]);

            send_json(true, 'Expense category updated successfully', [
                'id'          => $id,
                'name'        => $name,
                'description' => $description
            ]);
            break;

        case 'delete_category':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('expenses_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (expenses_manage required)', null, [], 403);
            }
            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid Category ID is required.', null, ['id' => 'Invalid ID'], 422);
            }

            if ($id === 1) {
                send_json(false, 'The default expense category cannot be deleted.', null, [], 400);
            }

            $stmtCat = $db->prepare("SELECT id, name, description FROM expense_categories WHERE id = :id");
            $stmtCat->execute([':id' => $id]);
            $cat = $stmtCat->fetch();
            if (!$cat) {
                send_json(false, 'Expense category not found.', null, [], 404);
            }

            // Check if active (non-voided) expenses are assigned
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM expenses WHERE category_id = :id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id]);
            $activeCount = (int) $stmtCheck->fetchColumn();

            if ($activeCount > 0) {
                send_json(
                    false,
                    "Cannot delete category: {$activeCount} active expense(s) are currently assigned to it. Please reassign or delete the expenses first.",
                    null,
                    ['category_id' => 'Category has active expenses assigned'],
                    400
                );
            }

            // Transactional re-assignment of historical/voided expenses to Category 1, then deletion
            $db->beginTransaction();
            try {
                $stmtReassign = $db->prepare("UPDATE expenses SET category_id = 1 WHERE category_id = :id");
                $stmtReassign->execute([':id' => $id]);

                $stmtDelete = $db->prepare("DELETE FROM expense_categories WHERE id = :id");
                $stmtDelete->execute([':id' => $id]);

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            audit_log('expenses', 'DELETE_CATEGORY', $id, $cat, null);

            send_json(true, 'Expense category deleted successfully', ['id' => $id]);
            break;

        default:
            send_json(false, 'Invalid action specified.', null, ['action' => 'Unknown action: ' . $action], 400);
            break;
    }
} catch (PDOException $e) {
    error_log('[Expense Handler PDO Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    send_json(false, 'A database error occurred while processing your request.', null, [], 500);
} catch (Throwable $e) {
    error_log('[Expense Handler System Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    send_json(false, 'An unexpected server error occurred.', null, [], 500);
}

