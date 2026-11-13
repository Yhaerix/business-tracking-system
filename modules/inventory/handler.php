<?php
/**
 * Inventory Management & Movements Backend Handler
 * Small Business Sales and Expense Tracking System
 *
 * Actions:
 * - list_movements     (GET/POST, Permission: inventory_view)
 * - adjust_stock       (POST,     Permission: inventory_adjust)
 * - low_stock_summary  (GET/POST, Permission: inventory_view)
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
        // 1. LIST MOVEMENTS AUDIT TRAIL
        // =====================================================================
        case 'list_movements':
            if (!has_permission('inventory_view')) {
                send_json(false, 'Access denied: Insufficient permissions (inventory_view required)', null, [], 403);
            }

            $productId = !empty($input['product_id']) ? (int) $input['product_id'] : null;
            $movementType = trim((string) ($input['movement_type'] ?? ''));
            $dateFrom = trim((string) ($input['date_from'] ?? ''));
            $dateTo = trim((string) ($input['date_to'] ?? ''));
            $search = trim((string) ($input['search'] ?? ''));
            $page = max(1, (int) ($input['page'] ?? 1));
            $limit = min(100, max(1, (int) ($input['limit'] ?? 15)));
            $offset = ($page - 1) * $limit;

            $where = ['1=1'];
            $params = [];

            if ($productId !== null && $productId > 0) {
                $where[] = 'm.product_id = :product_id';
                $params[':product_id'] = $productId;
            }

            $validTypes = ['INITIAL', 'RESTOCK', 'SALE', 'RETURN', 'DAMAGE', 'ADJUSTMENT'];
            if (in_array($movementType, $validTypes, true)) {
                $where[] = 'm.movement_type = :movement_type';
                $params[':movement_type'] = $movementType;
            }

            if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
                $where[] = 'm.created_at >= :date_from';
                $params[':date_from'] = $dateFrom . ' 00:00:00';
            }

            if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                $where[] = 'm.created_at <= :date_to';
                $params[':date_to'] = $dateTo . ' 23:59:59';
            }

            if ($search !== '') {
                $where[] = '(p.name LIKE :search OR p.sku LIKE :search OR m.notes LIKE :search)';
                $params[':search'] = '%' . $search . '%';
            }

            $whereSql = implode(' AND ', $where);

            // Total count
            $stmtCount = $db->prepare("
                SELECT COUNT(*) 
                FROM inventory_movements m
                JOIN products p ON m.product_id = p.id
                WHERE {$whereSql}
            ");
            $stmtCount->execute($params);
            $total = (int) $stmtCount->fetchColumn();

            // Movement rows
            $query = "
                SELECT m.id, m.product_id, p.name AS product_name, p.sku,
                       m.user_id, u.full_name AS user_name, u.username,
                       m.movement_type, m.quantity, m.stock_before, m.stock_after,
                       m.reference_type, m.reference_id, m.notes, m.created_at
                FROM inventory_movements m
                JOIN products p ON m.product_id = p.id
                JOIN users u ON m.user_id = u.id
                WHERE {$whereSql}
                ORDER BY m.id DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $db->prepare($query);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $movements = $stmt->fetchAll();

            send_json(true, 'Movements retrieved successfully', [
                'movements' => $movements,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => (int) ceil($total / $limit)
                ]
            ]);
            break;

        // =====================================================================
        // 2. ATOMIC STOCK ADJUSTMENT WITH ROW LOCKING
        // =====================================================================
        case 'adjust_stock':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('inventory_adjust')) {
                send_json(false, 'Access denied: Insufficient permissions (inventory_adjust required)', null, [], 403);
            }
            require_csrf($input);

            $productId = (int) ($input['product_id'] ?? 0);
            $adjustmentType = strtoupper(trim((string) ($input['movement_type'] ?? $input['adjustment_type'] ?? '')));
            $quantity = abs((int) ($input['quantity'] ?? 0));
            $direction = strtoupper(trim((string) ($input['direction'] ?? '')));
            $notes = trim((string) ($input['notes'] ?? ''));
            $newCount = isset($input['new_count']) && $input['new_count'] !== '' ? (int) $input['new_count'] : null;

            $errors = [];
            if ($productId <= 0) {
                $errors['product_id'] = 'Valid product selection is required.';
            }

            $allowedTypes = ['RESTOCK', 'DAMAGE', 'ADJUSTMENT', 'RETURN', 'STOCK_IN', 'STOCK_OUT', 'FIX_COUNT'];
            if (!in_array($adjustmentType, $allowedTypes, true)) {
                $errors['movement_type'] = 'Invalid adjustment type. Must be Stock In, Stock Out, or Fix Count.';
            }

            if ($adjustmentType === 'FIX_COUNT' && $newCount !== null) {
                if ($newCount < 0) {
                    $errors['quantity'] = 'Counted stock cannot be negative.';
                }
            } elseif ($quantity <= 0) {
                $errors['quantity'] = 'Quantity must be a positive integer greater than zero.';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed', null, $errors, 422);
            }

            // Map frontend adjustment types to schema ENUM('INITIAL','RESTOCK','SALE','RETURN','DAMAGE','ADJUSTMENT')
            $dbMovementType = match ($adjustmentType) {
                'STOCK_IN' => 'RESTOCK',
                'STOCK_OUT' => 'DAMAGE',
                'FIX_COUNT' => 'ADJUSTMENT',
                default => $adjustmentType
            };

            // Determine initial stock delta based on business rules
            if ($adjustmentType === 'RESTOCK' || $adjustmentType === 'RETURN' || $adjustmentType === 'STOCK_IN') {
                $delta = $quantity;
            } elseif ($adjustmentType === 'DAMAGE' || $adjustmentType === 'STOCK_OUT') {
                $delta = -$quantity;
            } elseif ($adjustmentType === 'ADJUSTMENT' || $adjustmentType === 'FIX_COUNT') {
                if ($newCount !== null) {
                    $delta = 0; // Computed after fetching current stock
                } elseif ($direction === 'SUBTRACT') {
                    $delta = -$quantity;
                } else {
                    $delta = $quantity;
                }
            } else {
                $delta = $quantity;
            }

            // Execute atomic update with row locking
            $db->beginTransaction();
            try {
                // 1. Lock target product row
                $stmtLock = $db->prepare("
                    SELECT id, name, sku, current_stock, low_stock_threshold, status
                    FROM products
                    WHERE id = :id AND deleted_at IS NULL
                    FOR UPDATE
                ");
                $stmtLock->execute([':id' => $productId]);
                $product = $stmtLock->fetch();

                if (!$product) {
                    $db->rollBack();
                    send_json(false, 'Product not found or has been deactivated/deleted.', null, [], 404);
                }

                $stockBefore = (int) $product['current_stock'];

                // If FIX_COUNT with explicit target count, compute delta directly
                if ($adjustmentType === 'FIX_COUNT' && $newCount !== null) {
                    $delta = $newCount - $stockBefore;
                    $quantity = abs($delta);
                }

                $stockAfter = $stockBefore + $delta;

                // 2. Strict Negative Stock Guard
                if ($stockAfter < 0) {
                    $db->rollBack();
                    send_json(
                        false,
                        "Deduction quantity ({$quantity}) exceeds available stock ({$stockBefore}). Negative stock is not allowed.",
                        null,
                        ['quantity' => "Operation would result in negative stock ({$stockAfter})."],
                        422
                    );
                }

                // 3. Update current stock in products
                $stmtUpdate = $db->prepare("
                    UPDATE products 
                    SET current_stock = :stock_after, updated_at = NOW() 
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    ':stock_after' => $stockAfter,
                    ':id' => $productId
                ]);

                // 4. Record entry in inventory_movements
                $defaultNote = match ($adjustmentType) {
                    'RESTOCK', 'STOCK_IN' => 'Manual stock inflow (Stock In)',
                    'DAMAGE', 'STOCK_OUT' => 'Manual stock outflow (Stock Out)',
                    'RETURN' => 'Customer return processed back to stock',
                    'ADJUSTMENT', 'FIX_COUNT' => 'Physical inventory count reconciliation (Fix Count)',
                    default => 'Manual stock adjustment'
                };
                $finalNotes = $notes !== '' ? $notes : $defaultNote;

                $stmtMovement = $db->prepare("
                    INSERT INTO inventory_movements (
                        product_id, user_id, movement_type, quantity,
                        stock_before, stock_after, reference_type, reference_id,
                        notes, created_at
                    ) VALUES (
                        :product_id, :user_id, :movement_type, :quantity,
                        :stock_before, :stock_after, 'MANUAL_ADJUSTMENT', NULL,
                        :notes, NOW()
                    )
                ");
                $stmtMovement->execute([
                    ':product_id' => $productId,
                    ':user_id' => $currentUser['id'],
                    ':movement_type' => $dbMovementType,
                    ':quantity' => abs($delta),
                    ':stock_before' => $stockBefore,
                    ':stock_after' => $stockAfter,
                    ':notes' => $finalNotes
                ]);
                $movementId = (int) $db->lastInsertId();

                // 5. Low Stock Notification with 24-Hour Deduplication
                $lowStockThreshold = (int) $product['low_stock_threshold'];
                if ($stockAfter <= $lowStockThreshold) {
                    $stmtCheckNotify = $db->prepare("
                        SELECT id FROM notifications 
                        WHERE type = 'LOW_STOCK' 
                          AND reference_type = 'PRODUCT' 
                          AND reference_id = :product_id 
                          AND created_at >= (NOW() - INTERVAL 24 HOUR)
                        LIMIT 1
                    ");
                    $stmtCheckNotify->execute([':product_id' => $productId]);
                    $recentNotice = $stmtCheckNotify->fetch();

                    if (!$recentNotice) {
                        $stmtNotify = $db->prepare("
                            INSERT INTO notifications (
                                user_id, type, title, message, is_read, reference_type, reference_id, created_at
                            ) VALUES (
                                NULL, 'LOW_STOCK', :title, :message, 0, 'PRODUCT', :reference_id, NOW()
                            )
                        ");
                        $stmtNotify->execute([
                            ':title' => "Low Stock Alert: {$product['name']}",
                            ':message' => "Product [{$product['sku']}] \"{$product['name']}\" is low on stock ({$stockAfter} remaining, threshold: {$lowStockThreshold}).",
                            ':reference_id' => $productId
                        ]);
                    }
                }

                $db->commit();
            } catch (Throwable $ex) {
                $db->rollBack();
                throw $ex;
            }

            audit_log('inventory', 'ADJUST_STOCK', $productId, [
                'current_stock' => $stockBefore
            ], [
                'current_stock' => $stockAfter,
                'movement_type' => $adjustmentType,
                'delta' => $delta,
                'notes' => $finalNotes
            ]);

            send_json(true, 'Stock adjusted successfully', [
                'product_id' => $productId,
                'product_name' => $product['name'],
                'sku' => $product['sku'],
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'quantity' => abs($delta),
                'movement_type' => $adjustmentType,
                'movement_id' => $movementId,
                'is_low_stock' => ($stockAfter <= $product['low_stock_threshold'])
            ]);
            break;

        // =====================================================================
        // 3. LOW STOCK & INVENTORY SUMMARY
        // =====================================================================
        case 'low_stock_summary':
            if (!has_permission('inventory_view')) {
                send_json(false, 'Access denied: Insufficient permissions (inventory_view required)', null, [], 403);
            }

            // Summary metrics
            $stmtMetrics = $db->query("
                SELECT 
                    COUNT(*) AS total_products,
                    SUM(CASE WHEN current_stock <= low_stock_threshold AND current_stock > 0 THEN 1 ELSE 0 END) AS low_stock_count,
                    SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
                    COALESCE(SUM(current_stock * cost_price), 0.00) AS total_inventory_cost_value,
                    COALESCE(SUM(current_stock * selling_price), 0.00) AS total_inventory_retail_value
                FROM products
                WHERE deleted_at IS NULL AND status = 'active'
            ");
            $metrics = $stmtMetrics->fetch();

            // Low stock products list
            $stmtList = $db->query("
                SELECT p.id, p.sku, p.name, c.name AS category_name,
                       p.current_stock, p.low_stock_threshold,
                       p.cost_price, p.selling_price,
                       (p.low_stock_threshold - p.current_stock) AS deficit
                FROM products p
                JOIN categories c ON p.category_id = c.id
                WHERE p.deleted_at IS NULL 
                  AND p.status = 'active'
                  AND p.current_stock <= p.low_stock_threshold
                ORDER BY p.current_stock ASC, (p.low_stock_threshold - p.current_stock) DESC
            ");
            $lowStockProducts = $stmtList->fetchAll();

            send_json(true, 'Low stock summary retrieved successfully', [
                'summary' => [
                    'total_products' => (int) ($metrics['total_products'] ?? 0),
                    'low_stock_count' => (int) ($metrics['low_stock_count'] ?? 0),
                    'out_of_stock_count' => (int) ($metrics['out_of_stock_count'] ?? 0),
                    'total_inventory_cost_value' => round((float) ($metrics['total_inventory_cost_value'] ?? 0), 2),
                    'total_inventory_retail_value' => round((float) ($metrics['total_inventory_retail_value'] ?? 0), 2)
                ],
                'low_stock_products' => $lowStockProducts
            ]);
            break;

        default:
            send_json(false, 'Invalid or unrecognized action requested.', null, ['action' => $action], 400);
            break;
    }
} catch (PDOException $e) {
    error_log('[Inventory Handler Database Error] ' . $e->getMessage());
    send_json(false, 'A database error occurred while processing inventory data.', null, [], 500);
} catch (Throwable $e) {
    error_log('[Inventory Handler System Error] ' . $e->getMessage());
    send_json(false, 'An unexpected server error occurred.', null, [], 500);
}

