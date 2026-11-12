<?php
/**
 * Product Catalog & Category Backend Handler
 * Small Business Sales and Expense Tracking System
 *
 * Actions:
 * - list_categories   (GET/POST, Permission: products_view)
 * - create_category   (POST,     Permission: products_manage)
 * - update_category   (POST,     Permission: products_manage)
 * - delete_category   (POST,     Permission: products_manage)
 * - list_products     (GET/POST, Permission: products_view)
 * - get_product       (GET/POST, Permission: products_view)
 * - create_product    (POST,     Permission: products_manage)
 * - update_product    (POST,     Permission: products_manage)
 * - delete_product    (POST,     Permission: products_manage)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

// Helper: Standardized JSON output
function send_json(bool $success, string $message, $data = null, array $errors = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: Extract request payload (supports JSON and application/x-www-form-urlencoded / FormData)
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
        // CATEGORY DOMAIN ACTIONS
        // =====================================================================

        case 'list_categories':
            if (!has_permission('products_view')) {
                send_json(false, 'Access denied: Insufficient permissions (products_view required)', null, [], 403);
            }

            $stmt = $db->query("
                SELECT c.id, c.name, c.description, c.created_at,
                       COUNT(p.id) AS product_count
                FROM categories c
                LEFT JOIN products p ON c.id = p.category_id AND p.deleted_at IS NULL
                GROUP BY c.id
                ORDER BY c.name ASC
            ");
            $categories = $stmt->fetchAll();

            send_json(true, 'Categories retrieved successfully', $categories);
            break;

        case 'create_category':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('products_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (products_manage required)', null, [], 403);
            }
            require_csrf($input);

            $name = trim((string) ($input['name'] ?? ''));
            $description = trim((string) ($input['description'] ?? ''));

            $errors = [];
            if ($name === '') {
                $errors['name'] = 'Category name is required.';
            } elseif (mb_strlen($name) > 100) {
                $errors['name'] = 'Category name cannot exceed 100 characters.';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            // Check duplicate category name
            $stmtCheck = $db->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) LIMIT 1");
            $stmtCheck->execute([':name' => $name]);
            if ($stmtCheck->fetch()) {
                send_json(false, 'Category name already exists.', null, ['name' => 'A category with this name already exists.'], 422);
            }

            $stmt = $db->prepare("
                INSERT INTO categories (name, description, created_at)
                VALUES (:name, :description, NOW())
            ");
            $stmt->execute([
                ':name' => $name,
                ':description' => $description !== '' ? $description : null
            ]);
            $categoryId = (int) $db->lastInsertId();

            audit_log('products', 'CREATE_CATEGORY', $categoryId, null, [
                'name' => $name,
                'description' => $description
            ]);

            send_json(true, 'Category created successfully', [
                'id' => $categoryId,
                'name' => $name,
                'description' => $description
            ], [], 201);
            break;

        case 'update_category':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('products_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (products_manage required)', null, [], 403);
            }
            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            $name = trim((string) ($input['name'] ?? ''));
            $description = trim((string) ($input['description'] ?? ''));

            if ($id <= 0) {
                send_json(false, 'Valid Category ID is required.', null, ['id' => 'Invalid category ID'], 422);
            }

            $stmtOld = $db->prepare("SELECT id, name, description FROM categories WHERE id = :id");
            $stmtOld->execute([':id' => $id]);
            $oldCat = $stmtOld->fetch();
            if (!$oldCat) {
                send_json(false, 'Category not found.', null, [], 404);
            }

            $errors = [];
            if ($name === '') {
                $errors['name'] = 'Category name is required.';
            } elseif (mb_strlen($name) > 100) {
                $errors['name'] = 'Category name cannot exceed 100 characters.';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            // Check duplicate name excluding self
            $stmtCheck = $db->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) AND id != :id LIMIT 1");
            $stmtCheck->execute([':name' => $name, ':id' => $id]);
            if ($stmtCheck->fetch()) {
                send_json(false, 'Category name already in use by another category.', null, ['name' => 'Category name already in use.'], 422);
            }

            $stmtUpdate = $db->prepare("
                UPDATE categories 
                SET name = :name, description = :description 
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':name' => $name,
                ':description' => $description !== '' ? $description : null,
                ':id' => $id
            ]);

            audit_log('products', 'UPDATE_CATEGORY', $id, $oldCat, [
                'name' => $name,
                'description' => $description
            ]);

            send_json(true, 'Category updated successfully', [
                'id' => $id,
                'name' => $name,
                'description' => $description
            ]);
            break;

        case 'delete_category':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('products_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (products_manage required)', null, [], 403);
            }
            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid Category ID is required.', null, ['id' => 'Invalid category ID'], 422);
            }

            if ($id === 1) {
                send_json(false, 'The default catalog category cannot be deleted.', null, [], 400);
            }

            $stmtCat = $db->prepare("SELECT id, name, description FROM categories WHERE id = :id");
            $stmtCat->execute([':id' => $id]);
            $cat = $stmtCat->fetch();
            if (!$cat) {
                send_json(false, 'Category not found.', null, [], 404);
            }

            // Check if active (non-deleted) products are assigned
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = :id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id]);
            $activeProductCount = (int) $stmtCheck->fetchColumn();

            if ($activeProductCount > 0) {
                send_json(
                    false,
                    "Cannot delete category: {$activeProductCount} active product(s) are currently assigned to it. Please reassign or delete them first.",
                    null,
                    ['category_id' => 'Category has active products assigned'],
                    400
                );
            }

            // Reassign any soft-deleted products to default category (ID: 1) and delete category in transaction
            $db->beginTransaction();
            try {
                $stmtReassign = $db->prepare("UPDATE products SET category_id = 1 WHERE category_id = :id");
                $stmtReassign->execute([':id' => $id]);

                $stmtDelete = $db->prepare("DELETE FROM categories WHERE id = :id");
                $stmtDelete->execute([':id' => $id]);

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            audit_log('products', 'DELETE_CATEGORY', $id, $cat, null);

            send_json(true, 'Category deleted successfully', ['id' => $id]);
            break;

        // =====================================================================
        // PRODUCT CATALOG DOMAIN ACTIONS
        // =====================================================================

        case 'list_products':
            if (!has_permission('products_view')) {
                send_json(false, 'Access denied: Insufficient permissions (products_view required)', null, [], 403);
            }

            $search = trim((string) ($input['search'] ?? ''));
            $categoryId = !empty($input['category_id']) ? (int) $input['category_id'] : null;
            $status = trim((string) ($input['status'] ?? 'all'));
            $stockStatus = trim((string) ($input['stock_status'] ?? 'all'));
            $page = max(1, (int) ($input['page'] ?? 1));
            $limit = min(100, max(1, (int) ($input['limit'] ?? 10)));
            $offset = ($page - 1) * $limit;

            $allowedSorts = ['name', 'sku', 'current_stock', 'cost_price', 'selling_price', 'created_at'];
            $sortBy = in_array($input['sort_by'] ?? '', $allowedSorts, true) ? 'p.' . $input['sort_by'] : 'p.created_at';
            $sortDir = strtoupper($input['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            $where = ['p.deleted_at IS NULL'];
            $params = [];

            if ($search !== '') {
                $where[] = '(p.name LIKE :search OR p.sku LIKE :search)';
                $params[':search'] = '%' . $search . '%';
            }

            if ($categoryId !== null && $categoryId > 0) {
                $where[] = 'p.category_id = :category_id';
                $params[':category_id'] = $categoryId;
            }

            if ($status === 'active' || $status === 'inactive') {
                $where[] = 'p.status = :status';
                $params[':status'] = $status;
            }

            if ($stockStatus === 'low_stock') {
                $where[] = 'p.current_stock <= p.low_stock_threshold AND p.current_stock > 0';
            } elseif ($stockStatus === 'out_of_stock') {
                $where[] = 'p.current_stock <= 0';
            } elseif ($stockStatus === 'in_stock') {
                $where[] = 'p.current_stock > p.low_stock_threshold';
            }

            $whereSql = implode(' AND ', $where);

            // Total count
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSql}");
            $stmtCount->execute($params);
            $total = (int) $stmtCount->fetchColumn();

            // Product rows
            $query = "
                SELECT p.id, p.category_id, c.name AS category_name,
                       p.sku, p.name, p.cost_price, p.selling_price,
                       p.current_stock, p.low_stock_threshold, p.status,
                       ROUND(p.current_stock * p.cost_price, 2) AS stock_value,
                       (p.current_stock <= p.low_stock_threshold) AS is_low_stock,
                       p.created_at, p.updated_at
                FROM products p
                JOIN categories c ON p.category_id = c.id
                WHERE {$whereSql}
                ORDER BY {$sortBy} {$sortDir}
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $db->prepare($query);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $products = $stmt->fetchAll();

            send_json(true, 'Products retrieved successfully', [
                'products' => $products,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => (int) ceil($total / $limit)
                ]
            ]);
            break;

        case 'get_product':
            if (!has_permission('products_view')) {
                send_json(false, 'Access denied: Insufficient permissions (products_view required)', null, [], 403);
            }

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid Product ID is required', null, ['id' => 'Invalid product ID'], 422);
            }

            $stmt = $db->prepare("
                SELECT p.id, p.category_id, c.name AS category_name,
                       p.sku, p.name, p.cost_price, p.selling_price,
                       p.current_stock, p.low_stock_threshold, p.status,
                       p.created_at, p.updated_at
                FROM products p
                JOIN categories c ON p.category_id = c.id
                WHERE p.id = :id AND p.deleted_at IS NULL
            ");
            $stmt->execute([':id' => $id]);
            $product = $stmt->fetch();

            if (!$product) {
                send_json(false, 'Product not found', null, [], 404);
            }

            send_json(true, 'Product retrieved successfully', $product);
            break;

        case 'create_product':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('products_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (products_manage required)', null, [], 403);
            }
            require_csrf($input);

            $categoryId = (int) ($input['category_id'] ?? 0);
            $sku = strtoupper(trim((string) ($input['sku'] ?? '')));
            $name = trim((string) ($input['name'] ?? ''));
            $costPrice = (float) ($input['cost_price'] ?? 0.0);
            $sellingPrice = (float) ($input['selling_price'] ?? 0.0);
            $initialStock = max(0, (int) ($input['current_stock'] ?? 0));
            $lowStockThreshold = max(0, (int) ($input['low_stock_threshold'] ?? 5));
            $status = in_array($input['status'] ?? 'active', ['active', 'inactive'], true) ? $input['status'] : 'active';

            $errors = [];
            if ($categoryId <= 0) {
                $errors['category_id'] = 'Valid category selection is required.';
            } else {
                $catCheck = $db->prepare("SELECT id FROM categories WHERE id = :id");
                $catCheck->execute([':id' => $categoryId]);
                if (!$catCheck->fetch()) {
                    $errors['category_id'] = 'Selected category does not exist.';
                }
            }

            if ($name === '') {
                $errors['name'] = 'Product name is required.';
            } elseif (mb_strlen($name) > 150) {
                $errors['name'] = 'Product name cannot exceed 150 characters.';
            }

            // Auto-generate SKU if omitted
            if ($sku === '') {
                $sku = 'PRD-' . strtoupper(substr(uniqid(), -6));
            } else {
                if (!preg_match('/^[A-Z0-9_-]{2,64}$/i', $sku)) {
                    $errors['sku'] = 'SKU must be 2-64 alphanumeric characters, dashes, or underscores.';
                } else {
                    $stmtSku = $db->prepare("SELECT id FROM products WHERE sku = :sku LIMIT 1");
                    $stmtSku->execute([':sku' => $sku]);
                    if ($stmtSku->fetch()) {
                        $errors['sku'] = 'SKU already exists. Please choose a unique SKU.';
                    }
                }
            }

            if ($costPrice < 0) {
                $errors['cost_price'] = 'Cost price cannot be negative.';
            }
            if ($sellingPrice < 0) {
                $errors['selling_price'] = 'Selling price cannot be negative.';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            // ATOMIC INSERTION WITH INVENTORY INITIALIZATION
            $db->beginTransaction();
            try {
                $stmtInsert = $db->prepare("
                    INSERT INTO products (
                        category_id, sku, name, cost_price, selling_price, 
                        current_stock, low_stock_threshold, status, created_at
                    ) VALUES (
                        :category_id, :sku, :name, :cost_price, :selling_price, 
                        :current_stock, :low_stock_threshold, :status, NOW()
                    )
                ");
                $stmtInsert->execute([
                    ':category_id' => $categoryId,
                    ':sku' => $sku,
                    ':name' => $name,
                    ':cost_price' => number_format($costPrice, 2, '.', ''),
                    ':selling_price' => number_format($sellingPrice, 2, '.', ''),
                    ':current_stock' => $initialStock,
                    ':low_stock_threshold' => $lowStockThreshold,
                    ':status' => $status
                ]);
                $productId = (int) $db->lastInsertId();

                // If starting with inventory, write an INITIAL stock movement
                if ($initialStock > 0) {
                    $stmtMovement = $db->prepare("
                        INSERT INTO inventory_movements (
                            product_id, user_id, movement_type, quantity,
                            stock_before, stock_after, reference_type, reference_id,
                            notes, created_at
                        ) VALUES (
                            :product_id, :user_id, 'INITIAL', :quantity,
                            0, :stock_after, 'INITIAL_SETUP', NULL,
                            'Initial inventory upon product catalog creation', NOW()
                        )
                    ");
                    $stmtMovement->execute([
                        ':product_id' => $productId,
                        ':user_id' => $currentUser['id'],
                        ':quantity' => $initialStock,
                        ':stock_after' => $initialStock
                    ]);
                }

                // If stock starts at or below threshold, create low-stock notification
                if ($initialStock <= $lowStockThreshold) {
                    $stmtNotify = $db->prepare("
                        INSERT INTO notifications (
                            user_id, type, title, message, is_read, reference_type, reference_id, created_at
                        ) VALUES (
                            NULL, 'LOW_STOCK', :title, :message, 0, 'PRODUCT', :reference_id, NOW()
                        )
                    ");
                    $stmtNotify->execute([
                        ':title' => "Low Stock Alert: {$name}",
                        ':message' => "Product [{$sku}] \"{$name}\" has low initial stock ({$initialStock} units, threshold: {$lowStockThreshold}).",
                        ':reference_id' => $productId
                    ]);
                }

                $db->commit();
            } catch (Throwable $ex) {
                $db->rollBack();
                throw $ex;
            }

            audit_log('products', 'CREATE_PRODUCT', $productId, null, [
                'sku' => $sku,
                'name' => $name,
                'category_id' => $categoryId,
                'selling_price' => $sellingPrice,
                'current_stock' => $initialStock
            ]);

            send_json(true, 'Product created successfully', [
                'id' => $productId,
                'sku' => $sku,
                'name' => $name,
                'current_stock' => $initialStock
            ], [], 201);
            break;

        case 'update_product':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('products_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (products_manage required)', null, [], 403);
            }
            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            $categoryId = (int) ($input['category_id'] ?? 0);
            $sku = strtoupper(trim((string) ($input['sku'] ?? '')));
            $name = trim((string) ($input['name'] ?? ''));
            $costPrice = (float) ($input['cost_price'] ?? 0.0);
            $sellingPrice = (float) ($input['selling_price'] ?? 0.0);
            $lowStockThreshold = max(0, (int) ($input['low_stock_threshold'] ?? 5));
            $status = in_array($input['status'] ?? 'active', ['active', 'inactive'], true) ? $input['status'] : 'active';

            if ($id <= 0) {
                send_json(false, 'Valid Product ID is required', null, ['id' => 'Invalid product ID'], 422);
            }

            $stmtOld = $db->prepare("SELECT * FROM products WHERE id = :id AND deleted_at IS NULL");
            $stmtOld->execute([':id' => $id]);
            $oldProd = $stmtOld->fetch();
            if (!$oldProd) {
                send_json(false, 'Product not found', null, [], 404);
            }

            $errors = [];
            if ($categoryId <= 0) {
                $errors['category_id'] = 'Valid category selection is required.';
            } else {
                $catCheck = $db->prepare("SELECT id FROM categories WHERE id = :id");
                $catCheck->execute([':id' => $categoryId]);
                if (!$catCheck->fetch()) {
                    $errors['category_id'] = 'Selected category does not exist.';
                }
            }

            if ($name === '') {
                $errors['name'] = 'Product name is required.';
            } elseif (mb_strlen($name) > 150) {
                $errors['name'] = 'Product name cannot exceed 150 characters.';
            }

            if ($sku === '') {
                $errors['sku'] = 'SKU is required.';
            } elseif (!preg_match('/^[A-Z0-9_-]{2,64}$/i', $sku)) {
                $errors['sku'] = 'SKU must be 2-64 alphanumeric characters, dashes, or underscores.';
            } else {
                $stmtSku = $db->prepare("SELECT id FROM products WHERE sku = :sku AND id != :id LIMIT 1");
                $stmtSku->execute([':sku' => $sku, ':id' => $id]);
                if ($stmtSku->fetch()) {
                    $errors['sku'] = 'SKU already in use by another product.';
                }
            }

            if ($costPrice < 0) {
                $errors['cost_price'] = 'Cost price cannot be negative.';
            }
            if ($sellingPrice < 0) {
                $errors['selling_price'] = 'Selling price cannot be negative.';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            // Note: current_stock is deliberately omitted from update_product to preserve inventory movement integrity
            $stmtUpdate = $db->prepare("
                UPDATE products 
                SET category_id = :category_id,
                    sku = :sku,
                    name = :name,
                    cost_price = :cost_price,
                    selling_price = :selling_price,
                    low_stock_threshold = :low_stock_threshold,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':category_id' => $categoryId,
                ':sku' => $sku,
                ':name' => $name,
                ':cost_price' => number_format($costPrice, 2, '.', ''),
                ':selling_price' => number_format($sellingPrice, 2, '.', ''),
                ':low_stock_threshold' => $lowStockThreshold,
                ':status' => $status,
                ':id' => $id
            ]);

            audit_log('products', 'UPDATE_PRODUCT', $id, $oldProd, [
                'sku' => $sku,
                'name' => $name,
                'category_id' => $categoryId,
                'cost_price' => $costPrice,
                'selling_price' => $sellingPrice,
                'low_stock_threshold' => $lowStockThreshold,
                'status' => $status
            ]);

            send_json(true, 'Product updated successfully', ['id' => $id]);
            break;

        case 'delete_product':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('products_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (products_manage required)', null, [], 403);
            }
            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid Product ID is required', null, ['id' => 'Invalid product ID'], 422);
            }

            $stmtOld = $db->prepare("SELECT * FROM products WHERE id = :id AND deleted_at IS NULL");
            $stmtOld->execute([':id' => $id]);
            $oldProd = $stmtOld->fetch();
            if (!$oldProd) {
                send_json(false, 'Product not found or already deleted', null, [], 404);
            }

            // Soft-delete pattern preserving historical integrity of past orders and movement logs
            $stmtDel = $db->prepare("
                UPDATE products 
                SET deleted_at = NOW(), status = 'inactive' 
                WHERE id = :id
            ");
            $stmtDel->execute([':id' => $id]);

            audit_log('products', 'DELETE_PRODUCT', $id, $oldProd, [
                'deleted_at' => date('Y-m-d H:i:s')
            ]);

            send_json(true, 'Product deleted successfully', ['id' => $id]);
            break;

        default:
            send_json(false, 'Invalid or unrecognized action requested.', null, ['action' => $action], 400);
            break;
    }
} catch (PDOException $e) {
    error_log('[Products Handler Database Error] ' . $e->getMessage());
    send_json(false, 'A database error occurred while processing the product catalog.', null, [], 500);
} catch (Throwable $e) {
    error_log('[Products Handler System Error] ' . $e->getMessage());
    send_json(false, 'An unexpected server error occurred.', null, [], 500);
}

