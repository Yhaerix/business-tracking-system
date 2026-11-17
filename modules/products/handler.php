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
                $where[] = '(p.name LIKE :search_name OR p.sku LIKE :search_sku)';
                $params[':search_name'] = '%' . $search . '%';
                $params[':search_sku'] = '%' . $search . '%';
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
                       p.sku, p.name, p.description, p.specs, p.color, p.storage,
                       p.condition_type, p.warranty,
                       p.cost_price, p.selling_price,
                       p.current_stock, p.low_stock_threshold, p.status,
                       ROUND(p.current_stock * p.cost_price, 2) AS stock_value,
                       (p.current_stock <= p.low_stock_threshold) AS is_low_stock,
                       (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, display_order ASC, id ASC LIMIT 1) AS primary_image,
                       (SELECT COUNT(*) FROM product_images WHERE product_id = p.id) AS image_count,
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
                       p.sku, p.name, p.description, p.specs, p.color, p.storage,
                       p.condition_type, p.warranty,
                       p.cost_price, p.selling_price,
                       p.current_stock, p.low_stock_threshold, p.status,
                       (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, display_order ASC, id ASC LIMIT 1) AS primary_image,
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

            // Retrieve all product images
            $stmtImages = $db->prepare("
                SELECT id, product_id, image_path, is_primary, display_order, created_at
                FROM product_images
                WHERE product_id = :id
                ORDER BY is_primary DESC, display_order ASC, id ASC
            ");
            $stmtImages->execute([':id' => $id]);
            $product['images'] = $stmtImages->fetchAll();

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
            $description = trim((string) ($input['description'] ?? ''));
            $specs = trim((string) ($input['specs'] ?? ''));
            $rawColor = $input['color'] ?? '';
            if (is_array($rawColor)) {
                $cleanColors = array_filter(array_map('trim', $rawColor));
                $color = implode(', ', $cleanColors);
            } else {
                $parts = explode(',', (string) $rawColor);
                $cleanColors = array_filter(array_map('trim', $parts));
                $color = implode(', ', $cleanColors);
            }
            $storage = trim((string) ($input['storage'] ?? ''));
            $conditionType = trim((string) ($input['condition_type'] ?? 'Brand New Sealed'));
            $warranty = trim((string) ($input['warranty'] ?? '1 Year Official Apple Warranty'));
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
                        category_id, sku, name, description, specs, color, storage, condition_type, warranty,
                        cost_price, selling_price, current_stock, low_stock_threshold, status, created_at
                    ) VALUES (
                        :category_id, :sku, :name, :description, :specs, :color, :storage, :condition_type, :warranty,
                        :cost_price, :selling_price, :current_stock, :low_stock_threshold, :status, NOW()
                    )
                ");
                $stmtInsert->execute([
                    ':category_id' => $categoryId,
                    ':sku' => $sku,
                    ':name' => $name,
                    ':description' => $description !== '' ? $description : null,
                    ':specs' => $specs !== '' ? $specs : null,
                    ':color' => $color !== '' ? $color : null,
                    ':storage' => $storage !== '' ? $storage : null,
                    ':condition_type' => $conditionType !== '' ? $conditionType : 'Brand New Sealed',
                    ':warranty' => $warranty !== '' ? $warranty : '1 Year Official Apple Warranty',
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

                // Handle optional direct photo upload on creation
                $uploadedImagePath = null;
                $uploadFile = null;
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $uploadFile = $_FILES['photo'];
                } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $uploadFile = $_FILES['image'];
                }

                if ($uploadFile) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $uploadFile['tmp_name']);
                    finfo_close($finfo);

                    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
                    $allowedExts  = ['jpg', 'jpeg', 'png', 'webp'];
                    $ext = strtolower(pathinfo($uploadFile['name'], PATHINFO_EXTENSION));
                    if ($ext === 'jpeg') $ext = 'jpg';

                    if (in_array($mimeType, $allowedMimes, true) && in_array($ext, $allowedExts, true) && $uploadFile['size'] <= 5 * 1024 * 1024) {
                        $baseDir = __DIR__ . '/../../uploads/products';
                        if (!is_dir($baseDir)) {
                            mkdir($baseDir, 0755, true);
                        }
                        $targetDir = realpath($baseDir);
                        $filename = 'prod_' . $productId . '_' . bin2hex(random_bytes(10)) . '.' . $ext;
                        $destPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
                        $relativeDbPath = 'uploads/products/' . $filename;

                        if (move_uploaded_file($uploadFile['tmp_name'], $destPath)) {
                            $uploadedImagePath = $relativeDbPath;
                            $stmtImg = $db->prepare("
                                INSERT INTO product_images (product_id, image_path, is_primary, display_order, created_at)
                                VALUES (:product_id, :image_path, 1, 0, NOW())
                            ");
                            $stmtImg->execute([
                                ':product_id' => $productId,
                                ':image_path' => $relativeDbPath
                            ]);
                        }
                    }
                }

                $db->commit();
            } catch (Throwable $ex) {
                $db->rollBack();
                throw $ex;
            }

            $createAuditValues = [
                'sku' => $sku,
                'name' => $name,
                'category_id' => $categoryId,
                'cost_price' => $costPrice,
                'selling_price' => $sellingPrice,
                'current_stock' => $initialStock,
                'low_stock_threshold' => $lowStockThreshold,
                'status' => $status,
                'color' => $color,
                'storage' => $storage,
                'condition_type' => $conditionType,
                'warranty' => $warranty,
                'specs' => $specs,
                'description' => $description
            ];
            if ($uploadedImagePath !== null) {
                $createAuditValues['primary_image'] = $uploadedImagePath;
            }

            audit_log('products', 'CREATE_PRODUCT', $productId, null, $createAuditValues);

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
            $description = trim((string) ($input['description'] ?? ''));
            $specs = trim((string) ($input['specs'] ?? ''));
            $rawColor = $input['color'] ?? '';
            if (is_array($rawColor)) {
                $cleanColors = array_filter(array_map('trim', $rawColor));
                $color = implode(', ', $cleanColors);
            } else {
                $parts = explode(',', (string) $rawColor);
                $cleanColors = array_filter(array_map('trim', $parts));
                $color = implode(', ', $cleanColors);
            }
            $storage = trim((string) ($input['storage'] ?? ''));
            $conditionType = trim((string) ($input['condition_type'] ?? 'Brand New Sealed'));
            $warranty = trim((string) ($input['warranty'] ?? '1 Year Official Apple Warranty'));
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

            // Retrieve current primary image to accurately track image changes in audit logs
            $stmtOldImg = $db->prepare("SELECT image_path FROM product_images WHERE product_id = :id AND is_primary = 1 LIMIT 1");
            $stmtOldImg->execute([':id' => $id]);
            $oldImgPath = $stmtOldImg->fetchColumn();
            $oldProd['primary_image'] = $oldImgPath ?: null;

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
                    description = :description,
                    specs = :specs,
                    color = :color,
                    storage = :storage,
                    condition_type = :condition_type,
                    warranty = :warranty,
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
                ':description' => $description !== '' ? $description : null,
                ':specs' => $specs !== '' ? $specs : null,
                ':color' => $color !== '' ? $color : null,
                ':storage' => $storage !== '' ? $storage : null,
                ':condition_type' => $conditionType !== '' ? $conditionType : 'Brand New Sealed',
                ':warranty' => $warranty !== '' ? $warranty : '1 Year Official Apple Warranty',
                ':cost_price' => number_format($costPrice, 2, '.', ''),
                ':selling_price' => number_format($sellingPrice, 2, '.', ''),
                ':low_stock_threshold' => $lowStockThreshold,
                ':status' => $status,
                ':id' => $id
            ]);

            // Handle optional direct photo upload on product update
            $uploadedImagePath = null;
            $uploadFile = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $uploadFile = $_FILES['photo'];
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadFile = $_FILES['image'];
            }

            if ($uploadFile) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $uploadFile['tmp_name']);
                finfo_close($finfo);

                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
                $allowedExts  = ['jpg', 'jpeg', 'png', 'webp'];
                $ext = strtolower(pathinfo($uploadFile['name'], PATHINFO_EXTENSION));
                if ($ext === 'jpeg') $ext = 'jpg';

                if (in_array($mimeType, $allowedMimes, true) && in_array($ext, $allowedExts, true) && $uploadFile['size'] <= 5 * 1024 * 1024) {
                    $baseDir = __DIR__ . '/../../uploads/products';
                    if (!is_dir($baseDir)) {
                        mkdir($baseDir, 0755, true);
                    }
                    $targetDir = realpath($baseDir);
                    $filename = 'prod_' . $id . '_' . bin2hex(random_bytes(10)) . '.' . $ext;
                    $destPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
                    $relativeDbPath = 'uploads/products/' . $filename;

                    if (move_uploaded_file($uploadFile['tmp_name'], $destPath)) {
                        $uploadedImagePath = $relativeDbPath;
                        // Demote old primaries
                        $stmtReset = $db->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = :pid");
                        $stmtReset->execute([':pid' => $id]);

                        $stmtImg = $db->prepare("
                            INSERT INTO product_images (product_id, image_path, is_primary, display_order, created_at)
                            VALUES (:product_id, :image_path, 1, 0, NOW())
                        ");
                        $stmtImg->execute([
                            ':product_id' => $id,
                            ':image_path' => $relativeDbPath
                        ]);
                    }
                }
            }

            $newProdAudit = [
                'sku' => $sku,
                'name' => $name,
                'category_id' => $categoryId,
                'cost_price' => $costPrice,
                'selling_price' => $sellingPrice,
                'low_stock_threshold' => $lowStockThreshold,
                'status' => $status,
                'color' => $color,
                'storage' => $storage,
                'condition_type' => $conditionType,
                'warranty' => $warranty,
                'specs' => $specs,
                'description' => $description,
                'primary_image' => $uploadedImagePath !== null ? $uploadedImagePath : ($oldProd['primary_image'] ?? null)
            ];

            audit_log('products', 'UPDATE_PRODUCT', $id, $oldProd, $newProdAudit);

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

        // =====================================================================
        // PRODUCT MEDIA & GALLERY ACTIONS
        // =====================================================================

        case 'list_product_images':
            if (!has_permission('products_view')) {
                send_json(false, 'Access denied: Insufficient permissions (products_view required)', null, [], 403);
            }
            $productId = (int) ($input['product_id'] ?? 0);
            if ($productId <= 0) {
                send_json(false, 'Valid Product ID is required', null, ['product_id' => 'Invalid product ID'], 422);
            }

            $stmtImages = $db->prepare("
                SELECT id, product_id, image_path, is_primary, display_order, created_at
                FROM product_images
                WHERE product_id = :product_id
                ORDER BY is_primary DESC, display_order ASC, id ASC
            ");
            $stmtImages->execute([':product_id' => $productId]);
            $rawImages = $stmtImages->fetchAll();

            $images = [];
            foreach ($rawImages as $img) {
                $images[] = [
                    'id' => (int) $img['id'],
                    'product_id' => (int) $img['product_id'],
                    'image_path' => $img['image_path'],
                    'url' => '../../' . ltrim($img['image_path'], '/'),
                    'is_primary' => (int) $img['is_primary'],
                    'display_order' => (int) $img['display_order'],
                    'created_at' => $img['created_at']
                ];
            }

            send_json(true, 'Product images retrieved successfully', $images);
            break;

        case 'upload_product_images':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('products_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (products_manage required)', null, [], 403);
            }
            require_csrf($input);

            $productId = (int) ($input['product_id'] ?? 0);
            if ($productId <= 0) {
                send_json(false, 'Valid Product ID is required', null, ['product_id' => 'Invalid product ID'], 422);
            }

            // Verify product exists and active
            $stmtProd = $db->prepare("SELECT id, name FROM products WHERE id = :id AND deleted_at IS NULL");
            $stmtProd->execute([':id' => $productId]);
            $prod = $stmtProd->fetch();
            if (!$prod) {
                send_json(false, 'Product not found or inactive', null, [], 404);
            }

            // Normalize $_FILES
            $filesToProcess = [];
            if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                $count = count($_FILES['images']['name']);
                for ($i = 0; $i < $count; $i++) {
                    if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                        $filesToProcess[] = [
                            'name'     => $_FILES['images']['name'][$i],
                            'tmp_name' => $_FILES['images']['tmp_name'][$i],
                            'size'     => $_FILES['images']['size'][$i],
                            'error'    => $_FILES['images']['error'][$i]
                        ];
                    }
                }
            } elseif (isset($_FILES['images']) && !is_array($_FILES['images']['name']) && $_FILES['images']['error'] === UPLOAD_ERR_OK) {
                $filesToProcess[] = $_FILES['images'];
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $filesToProcess[] = $_FILES['image'];
            } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $filesToProcess[] = $_FILES['photo'];
            }

            if (empty($filesToProcess)) {
                send_json(false, 'No valid image files provided for upload.', null, ['images' => 'Please select at least one image file.'], 422);
            }

            // Destination directory
            $baseDir = __DIR__ . '/../../uploads/products';
            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0755, true);
            }
            $targetDir = realpath($baseDir);

            // Check if product currently has any primary image
            $stmtHasPrimary = $db->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = :pid AND is_primary = 1");
            $stmtHasPrimary->execute([':pid' => $productId]);
            $hasPrimary = ((int) $stmtHasPrimary->fetchColumn()) > 0;

            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            $allowedExts  = ['jpg', 'jpeg', 'png', 'webp'];
            $maxFileSize  = 5 * 1024 * 1024; // 5MB limit

            $uploadedRecords = [];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            $db->beginTransaction();
            try {
                foreach ($filesToProcess as $file) {
                    if ($file['size'] > $maxFileSize) {
                        throw new RuntimeException("File '{$file['name']}' exceeds the maximum allowed limit of 5MB.");
                    }

                    $mimeType = finfo_file($finfo, $file['tmp_name']);
                    if (!in_array($mimeType, $allowedMimes, true)) {
                        throw new RuntimeException("File '{$file['name']}' has an invalid MIME type ({$mimeType}). Only JPG, PNG, and WebP are allowed.");
                    }

                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExts, true)) {
                        throw new RuntimeException("File '{$file['name']}' has an untrusted extension.");
                    }
                    if ($ext === 'jpeg') {
                        $ext = 'jpg';
                    }

                    // Cryptographically secure filename
                    $filename = 'prod_' . $productId . '_' . bin2hex(random_bytes(10)) . '.' . $ext;
                    $destPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
                    $relativeDbPath = 'uploads/products/' . $filename;

                    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                        throw new RuntimeException("Failed to move uploaded file to disk storage.");
                    }

                    // Determine if primary
                    $isPrimary = 0;
                    if (!$hasPrimary) {
                        $isPrimary = 1;
                        $hasPrimary = true;
                    }

                    $stmtInsertImg = $db->prepare("
                        INSERT INTO product_images (product_id, image_path, is_primary, display_order, created_at)
                        VALUES (:product_id, :image_path, :is_primary, 0, NOW())
                    ");
                    $stmtInsertImg->execute([
                        ':product_id' => $productId,
                        ':image_path' => $relativeDbPath,
                        ':is_primary' => $isPrimary
                    ]);
                    $imageId = (int) $db->lastInsertId();

                    $uploadedRecords[] = [
                        'id' => $imageId,
                        'product_id' => $productId,
                        'image_path' => $relativeDbPath,
                        'url' => '../../' . $relativeDbPath,
                        'is_primary' => $isPrimary
                    ];
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                // Clean up any files saved in this transaction
                foreach ($uploadedRecords as $rec) {
                    $cleanupPath = __DIR__ . '/../../' . $rec['image_path'];
                    if (file_exists($cleanupPath)) {
                        @unlink($cleanupPath);
                    }
                }
                finfo_close($finfo);
                send_json(false, $e->getMessage(), null, ['upload' => $e->getMessage()], 422);
            }

            finfo_close($finfo);

            audit_log('products', 'UPLOAD_PRODUCT_IMAGE', $productId, null, [
                'count' => count($uploadedRecords),
                'product_name' => $prod['name'],
                'uploaded_images' => array_column($uploadedRecords, 'image_path')
            ]);

            send_json(true, 'Product images uploaded successfully', [
                'uploaded' => $uploadedRecords,
                'count' => count($uploadedRecords)
            ], [], 201);
            break;

        case 'set_primary_image':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('products_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (products_manage required)', null, [], 403);
            }
            require_csrf($input);

            $productId = (int) ($input['product_id'] ?? 0);
            $imageId = (int) ($input['image_id'] ?? 0);

            if ($productId <= 0 || $imageId <= 0) {
                send_json(false, 'Valid Product ID and Image ID are required.', null, [], 422);
            }

            // Verify image belongs to product
            $stmtCheck = $db->prepare("SELECT id FROM product_images WHERE id = :id AND product_id = :pid");
            $stmtCheck->execute([':id' => $imageId, ':pid' => $productId]);
            if (!$stmtCheck->fetch()) {
                send_json(false, 'Image does not belong to specified product.', null, [], 404);
            }

            // Fetch previous and new primary image path for comprehensive audit logging
            $stmtOldPrimary = $db->prepare("SELECT image_path FROM product_images WHERE product_id = :pid AND is_primary = 1 LIMIT 1");
            $stmtOldPrimary->execute([':pid' => $productId]);
            $oldPrimaryPath = $stmtOldPrimary->fetchColumn() ?: null;

            $stmtNewPrimary = $db->prepare("SELECT image_path FROM product_images WHERE id = :id");
            $stmtNewPrimary->execute([':id' => $imageId]);
            $newPrimaryPath = $stmtNewPrimary->fetchColumn() ?: null;

            $db->beginTransaction();
            try {
                // Reset all images of this product
                $stmtReset = $db->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = :pid");
                $stmtReset->execute([':pid' => $productId]);

                // Set target image as primary
                $stmtSet = $db->prepare("UPDATE product_images SET is_primary = 1 WHERE id = :id AND product_id = :pid");
                $stmtSet->execute([':id' => $imageId, ':pid' => $productId]);

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            audit_log('products', 'SET_PRIMARY_PRODUCT_IMAGE', $productId, 
                ['primary_image' => $oldPrimaryPath], 
                ['primary_image' => $newPrimaryPath]
            );

            send_json(true, 'Primary image updated successfully', [
                'product_id' => $productId,
                'primary_image_id' => $imageId
            ]);
            break;

        case 'delete_product_image':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('products_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (products_manage required)', null, [], 403);
            }
            require_csrf($input);

            $productId = (int) ($input['product_id'] ?? 0);
            $imageId = (int) ($input['image_id'] ?? 0);

            if ($productId <= 0 || $imageId <= 0) {
                send_json(false, 'Valid Product ID and Image ID are required.', null, [], 422);
            }

            $stmtImg = $db->prepare("SELECT id, image_path, is_primary FROM product_images WHERE id = :id AND product_id = :pid");
            $stmtImg->execute([':id' => $imageId, ':pid' => $productId]);
            $imageRow = $stmtImg->fetch();
            if (!$imageRow) {
                send_json(false, 'Product image not found.', null, [], 404);
            }

            $db->beginTransaction();
            try {
                // Delete record from database
                $stmtDel = $db->prepare("DELETE FROM product_images WHERE id = :id AND product_id = :pid");
                $stmtDel->execute([':id' => $imageId, ':pid' => $productId]);

                // If deleted image was primary, promote next available image
                if ((int)$imageRow['is_primary'] === 1) {
                    $stmtNext = $db->prepare("SELECT id FROM product_images WHERE product_id = :pid ORDER BY display_order ASC, id ASC LIMIT 1");
                    $stmtNext->execute([':pid' => $productId]);
                    $nextId = $stmtNext->fetchColumn();
                    if ($nextId) {
                        $stmtPromote = $db->prepare("UPDATE product_images SET is_primary = 1 WHERE id = :id");
                        $stmtPromote->execute([':id' => $nextId]);
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            // Safely unlink physical file from disk with path traversal guard
            $allowedBaseDir = realpath(__DIR__ . '/../../uploads/products');
            $rawFullPath = __DIR__ . '/../../' . $imageRow['image_path'];
            $realFilePath = realpath($rawFullPath);
            if ($realFilePath !== false && $allowedBaseDir !== false && str_starts_with($realFilePath, $allowedBaseDir)) {
                if (file_exists($realFilePath)) {
                    @unlink($realFilePath);
                }
            }

            audit_log('products', 'DELETE_PRODUCT_IMAGE', $productId, $imageRow, null);

            send_json(true, 'Product image deleted successfully', [
                'product_id' => $productId,
                'deleted_image_id' => $imageId
            ]);
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

