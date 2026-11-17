<?php
/**
 * Cash on Delivery (COD) Orders Backend Handler
 * Small Business Sales and Expense Tracking System
 *
 * Module: modules/orders/handler.php
 *
 * Actions:
 * - create_order       (POST,     Permission: orders_create)
 * - list_orders        (GET/POST, Permission: orders_view)
 * - get_order_details  (GET/POST, Permission: orders_view)
 * - update_status      (POST,     Permission: orders_update_status / orders_cancel / orders_remit_cod)
 * - add_note           (POST,     Permission: orders_view)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

/**
 * Standardized JSON envelope output helper
 */
function send_json(bool $success, string $message, mixed $data = null, array $errors = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
        'errors'  => $errors
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Request payload extraction helper (JSON and application/x-www-form-urlencoded)
 */
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

/**
 * Anti-CSRF verification helper
 */
function require_csrf(array $input): void
{
    $token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verify_csrf_token($token)) {
        send_json(false, 'Security token invalid or expired. Please refresh the page.', null, ['csrf' => 'Invalid or missing CSRF token'], 403);
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
        // 1. CREATE COD ORDER
        // =====================================================================
        case 'create_order':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            if (!has_permission('orders_create')) {
                send_json(false, 'Access denied: Insufficient permissions (orders_create required)', null, [], 403);
            }

            require_csrf($input);

            $customerName = trim((string) ($input['customer_name'] ?? ''));
            $customerPhone = trim((string) ($input['customer_phone'] ?? ''));
            $deliveryAddress = trim((string) ($input['delivery_address'] ?? ''));
            $deliveryNotes = trim((string) ($input['delivery_notes'] ?? ''));
            $discountAmount = isset($input['discount_amount']) && is_numeric($input['discount_amount'])
                ? round(max(0.00, (float) $input['discount_amount']), 2)
                : 0.00;
            $deliveryFee = isset($input['delivery_fee']) && is_numeric($input['delivery_fee'])
                ? round(max(0.00, (float) $input['delivery_fee']), 2)
                : 0.00;
            $rawItems = $input['items'] ?? [];

            $errors = [];
            if ($customerName === '') {
                $errors['customer_name'] = 'Customer name is required.';
            } elseif (mb_strlen($customerName) > 100) {
                $errors['customer_name'] = 'Customer name cannot exceed 100 characters.';
            }

            if ($customerPhone === '') {
                $errors['customer_phone'] = 'Customer phone number is required.';
            } elseif (mb_strlen($customerPhone) > 30) {
                $errors['customer_phone'] = 'Customer phone number cannot exceed 30 characters.';
            }

            if ($deliveryAddress === '') {
                $errors['delivery_address'] = 'Delivery address is required.';
            }

            if (!is_array($rawItems) || count($rawItems) === 0) {
                $errors['items'] = 'At least one line item is required to create an order.';
            }

            // Aggregate and validate item inputs
            $itemsMap = [];
            if (is_array($rawItems) && count($rawItems) > 0) {
                foreach ($rawItems as $idx => $itm) {
                    $pid = (int) ($itm['product_id'] ?? 0);
                    $qty = (int) ($itm['quantity'] ?? 0);
                    $customPrice = isset($itm['unit_price']) && is_numeric($itm['unit_price']) ? (float) $itm['unit_price'] : null;

                    if ($pid <= 0) {
                        $errors["items.{$idx}.product_id"] = 'A valid product must be selected for each item.';
                    }
                    if ($qty <= 0) {
                        $errors["items.{$idx}.quantity"] = 'Item quantity must be a positive integer of at least 1.';
                    }

                    if ($pid > 0 && $qty > 0) {
                        if (!isset($itemsMap[$pid])) {
                            $itemsMap[$pid] = [
                                'product_id' => $pid,
                                'quantity' => $qty,
                                'unit_price' => $customPrice
                            ];
                        } else {
                            $itemsMap[$pid]['quantity'] += $qty;
                        }
                    }
                }
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed. Please correct the highlighted errors.', null, $errors, 422);
            }

            // Begin atomic transaction
            $db->beginTransaction();

            try {
                // 1. Sequential order numbering with row lock
                $year = date('Y');
                $stmtSeq = $db->prepare("
                    SELECT order_number 
                    FROM orders 
                    WHERE order_number LIKE :prefix 
                    ORDER BY id DESC 
                    LIMIT 1 
                    FOR UPDATE
                ");
                $stmtSeq->execute([':prefix' => 'ORD-' . $year . '-%']);
                $lastOrder = $stmtSeq->fetch();

                $nextSeq = 1;
                if ($lastOrder && !empty($lastOrder['order_number'])) {
                    if (preg_match('/ORD-\d{4}-(\d+)/', (string) $lastOrder['order_number'], $matches)) {
                        $nextSeq = ((int) $matches[1]) + 1;
                    }
                }
                $orderNumber = sprintf('ORD-%s-%04d', $year, $nextSeq);

                // 2. Stock verification & snapshot calculation
                $subtotal = 0.00;
                $processedItems = [];

                $stmtProd = $db->prepare("
                    SELECT id, name, sku, cost_price, selling_price, current_stock 
                    FROM products 
                    WHERE id = :id AND deleted_at IS NULL 
                    FOR UPDATE
                ");

                foreach ($itemsMap as $pid => $itemData) {
                    $stmtProd->execute([':id' => $pid]);
                    $product = $stmtProd->fetch();

                    if (!$product) {
                        $db->rollBack();
                        send_json(false, "Product ID {$pid} not found or is currently inactive.", null, [
                            'product_id' => "Product ID {$pid} is unavailable."
                        ], 422);
                    }

                    $currentStock = (int) $product['current_stock'];
                    $requestedQty = (int) $itemData['quantity'];

                    if ($currentStock < $requestedQty) {
                        $db->rollBack();
                        send_json(
                            false,
                            "Insufficient stock for item {$product['name']}. Available: {$currentStock}, Requested: {$requestedQty}",
                            null,
                            ['stock' => "Item '{$product['name']}' has only {$currentStock} available in stock."],
                            422
                        );
                    }

                    $unitCost = (float) $product['cost_price'];
                    $unitPrice = ($itemData['unit_price'] !== null && $itemData['unit_price'] >= 0)
                        ? round((float) $itemData['unit_price'], 2)
                        : (float) $product['selling_price'];
                    $lineTotal = round($unitPrice * $requestedQty, 2);
                    $subtotal += $lineTotal;

                    $processedItems[] = [
                        'product_id'    => $pid,
                        'product_name'  => $product['name'],
                        'unit_cost'     => $unitCost,
                        'unit_price'    => $unitPrice,
                        'quantity'      => $requestedQty,
                        'line_total'    => $lineTotal,
                        'current_stock' => $currentStock
                    ];
                }

                // Total Calculation (Strictly zero tax rule: Total = Subtotal - Discount + Delivery)
                $totalAmount = max(0.00, round($subtotal - $discountAmount + $deliveryFee, 2));

                // 3. Insert into orders table
                $stmtOrder = $db->prepare("
                    INSERT INTO orders (
                        order_number, user_id, customer_name, customer_phone,
                        delivery_address, delivery_notes, subtotal, discount_amount,
                        delivery_fee, total_amount, order_status, payment_method,
                        payment_status, created_at, updated_at
                    ) VALUES (
                        :order_number, :user_id, :customer_name, :customer_phone,
                        :delivery_address, :delivery_notes, :subtotal, :discount_amount,
                        :delivery_fee, :total_amount, 'CONFIRMED', 'COD',
                        'PENDING_COLLECTION', NOW(), NOW()
                    )
                ");
                $stmtOrder->execute([
                    ':order_number'     => $orderNumber,
                    ':user_id'          => $currentUser['id'],
                    ':customer_name'    => $customerName,
                    ':customer_phone'   => $customerPhone,
                    ':delivery_address' => $deliveryAddress,
                    ':delivery_notes'   => $deliveryNotes !== '' ? $deliveryNotes : null,
                    ':subtotal'         => $subtotal,
                    ':discount_amount'  => $discountAmount,
                    ':delivery_fee'     => $deliveryFee,
                    ':total_amount'     => $totalAmount
                ]);
                $orderId = (int) $db->lastInsertId();

                // 4. Insert order_items, decrement product current_stock, and record inventory movements
                $stmtInsertItem = $db->prepare("
                    INSERT INTO order_items (
                        order_id, product_id, product_name, unit_cost, unit_price, quantity, line_total, created_at
                    ) VALUES (
                        :order_id, :product_id, :product_name, :unit_cost, :unit_price, :quantity, :line_total, NOW()
                    )
                ");

                $stmtUpdateStock = $db->prepare("
                    UPDATE products 
                    SET current_stock = current_stock - :qty, updated_at = NOW() 
                    WHERE id = :id
                ");

                $stmtMovement = $db->prepare("
                    INSERT INTO inventory_movements (
                        product_id, user_id, movement_type, quantity, stock_before, stock_after,
                        reference_type, reference_id, notes, created_at
                    ) VALUES (
                        :product_id, :user_id, 'SALE', :quantity, :stock_before, :stock_after,
                        'ORDER', :reference_id, :notes, NOW()
                    )
                ");

                foreach ($processedItems as $item) {
                    // Line item record
                    $stmtInsertItem->execute([
                        ':order_id'     => $orderId,
                        ':product_id'   => $item['product_id'],
                        ':product_name' => $item['product_name'],
                        ':unit_cost'    => $item['unit_cost'],
                        ':unit_price'   => $item['unit_price'],
                        ':quantity'     => $item['quantity'],
                        ':line_total'   => $item['line_total']
                    ]);

                    // Deduct stock
                    $stmtUpdateStock->execute([
                        ':qty' => $item['quantity'],
                        ':id'  => $item['product_id']
                    ]);

                    // Movement audit entry
                    $stockBefore = $item['current_stock'];
                    $stockAfter = $stockBefore - $item['quantity'];
                    $stmtMovement->execute([
                        ':product_id'   => $item['product_id'],
                        ':user_id'      => $currentUser['id'],
                        ':quantity'     => -$item['quantity'],
                        ':stock_before' => $stockBefore,
                        ':stock_after'  => $stockAfter,
                        ':reference_id' => $orderId,
                        ':notes'        => 'Order confirmed: ' . $orderNumber
                    ]);
                }

                // 5. Insert COD payment tracking record
                $stmtCod = $db->prepare("
                    INSERT INTO cod_payments (
                        order_id, amount_due, amount_collected, status, created_at
                    ) VALUES (
                        :order_id, :amount_due, 0.00, 'PENDING', NOW()
                    )
                ");
                $stmtCod->execute([
                    ':order_id'   => $orderId,
                    ':amount_due' => $totalAmount
                ]);

                // 6. Insert initial order note
                $authorName = $currentUser['username'] ?? ('User #' . $currentUser['id']);
                $initialNote = 'Order created and confirmed by ' . $authorName . ' with ' . count($processedItems) . ' item(s).';
                $stmtNote = $db->prepare("
                    INSERT INTO order_notes (order_id, user_id, note, created_at)
                    VALUES (:order_id, :user_id, :note, NOW())
                ");
                $stmtNote->execute([
                    ':order_id' => $orderId,
                    ':user_id'  => $currentUser['id'],
                    ':note'     => $initialNote
                ]);

                // 7. Security audit logging
                audit_log('orders', 'CREATE_ORDER', $orderId, null, [
                    'order_number'    => $orderNumber,
                    'customer_name'   => $customerName,
                    'total_amount'    => $totalAmount,
                    'item_count'      => count($processedItems)
                ]);

                $db->commit();

                send_json(true, 'Order created and confirmed successfully', [
                    'id'              => $orderId,
                    'order_number'    => $orderNumber,
                    'customer_name'   => $customerName,
                    'subtotal'        => $subtotal,
                    'discount_amount' => $discountAmount,
                    'delivery_fee'    => $deliveryFee,
                    'total_amount'    => $totalAmount,
                    'order_status'    => ORDER_STATUS_CONFIRMED,
                    'payment_status'  => PAYMENT_STATUS_PENDING
                ], [], 201);
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            break;

        // =====================================================================
        // 2. LIST ORDERS WITH PAGINATION & TOP KPI SUMMARY
        // =====================================================================
        case 'list_orders':
            if (!has_permission('orders_view')) {
                send_json(false, 'Access denied: Insufficient permissions (orders_view required)', null, [], 403);
            }

            $page = max(1, (int) ($input['page'] ?? 1));
            $limit = min(100, max(1, (int) ($input['limit'] ?? 15)));
            $offset = ($page - 1) * $limit;

            $search = trim((string) ($input['search'] ?? ''));
            $orderStatus = trim((string) ($input['order_status'] ?? ''));
            $paymentStatus = trim((string) ($input['payment_status'] ?? ''));
            $dateFrom = trim((string) ($input['date_from'] ?? ''));
            $dateTo = trim((string) ($input['date_to'] ?? ''));

            $where = ['o.deleted_at IS NULL'];
            $params = [];

            if ($search !== '') {
                $where[] = '(o.order_number LIKE :s_num OR o.customer_name LIKE :s_name OR o.customer_phone LIKE :s_phone)';
                $params[':s_num'] = '%' . $search . '%';
                $params[':s_name'] = '%' . $search . '%';
                $params[':s_phone'] = '%' . $search . '%';
            }

            if ($orderStatus !== '') {
                $where[] = 'o.order_status = :order_status';
                $params[':order_status'] = $orderStatus;
            }

            if ($paymentStatus !== '') {
                $where[] = 'o.payment_status = :payment_status';
                $params[':payment_status'] = $paymentStatus;
            }

            $paymentMode = strtoupper(trim((string) ($input['payment_mode'] ?? '')));
            if ($paymentMode === 'COD') {
                $where[] = "o.payment_method = 'COD'";
            } elseif ($paymentMode === 'PRESTIGE_COINS' || $paymentMode === 'COINS') {
                $where[] = "o.payment_method = 'PRESTIGE_COINS'";
            }

            if ($dateFrom !== '') {
                $where[] = 'o.created_at >= :date_from';
                $params[':date_from'] = $dateFrom . ' 00:00:00';
            }

            if ($dateTo !== '') {
                $where[] = 'o.created_at <= :date_to';
                $params[':date_to'] = $dateTo . ' 23:59:59';
            }

            $whereSql = implode(' AND ', $where);

            // Compute Top KPI Metrics
            $kpiStmt = $db->query("
                SELECT 
                    COUNT(CASE WHEN o.order_status IN ('PENDING', 'CONFIRMED') THEN 1 END) AS pending_count,
                    COUNT(CASE WHEN o.order_status = 'DISPATCHED' THEN 1 END) AS dispatched_count,
                    COUNT(CASE WHEN o.order_status = 'DELIVERED' THEN 1 END) AS delivered_count,
                    COALESCE(SUM(CASE WHEN cp.status = 'COLLECTED' THEN cp.amount_collected ELSE 0 END), 0.00) AS unremitted_cod_cash
                FROM orders o
                LEFT JOIN cod_payments cp ON o.id = cp.order_id
                WHERE o.deleted_at IS NULL
            ");
            $kpi = $kpiStmt->fetch();

            // Total count for current filter
            $countStmt = $db->prepare("SELECT COUNT(*) FROM orders o WHERE {$whereSql}");
            $countStmt->execute($params);
            $totalCount = (int) $countStmt->fetchColumn();
            $totalPages = $totalCount > 0 ? (int) ceil($totalCount / $limit) : 1;

            // Fetch paginated order records
            $orderSql = "
                SELECT 
                    o.id,
                    o.order_number,
                    o.user_id,
                    o.customer_name,
                    o.customer_phone,
                    o.delivery_address,
                    o.delivery_notes,
                    o.courier_name,
                    o.tracking_number,
                    o.shipping_status,
                    o.subtotal,
                    o.discount_amount,
                    o.delivery_fee,
                    o.total_amount,
                    o.order_status,
                    o.payment_method,
                    o.payment_status,
                    o.dispatched_at,
                    o.delivered_at,
                    o.cancelled_at,
                    o.cancellation_reason,
                    o.created_at,
                    u.full_name AS creator_name,
                    u.username AS creator_username,
                    cp.amount_due AS cod_amount_due,
                    cp.amount_collected AS cod_amount_collected,
                    cp.collected_by,
                    cp.collected_at,
                    cp.status AS cod_status,
                    cp.remitted_at
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.id
                LEFT JOIN cod_payments cp ON o.id = cp.order_id
                WHERE {$whereSql}
                ORDER BY o.id DESC
                LIMIT :limit OFFSET :offset
            ";
            $orderStmt = $db->prepare($orderSql);
            foreach ($params as $k => $v) {
                $orderStmt->bindValue($k, $v);
            }
            $orderStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $orderStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $orderStmt->execute();
            $orders = $orderStmt->fetchAll();

            // Fetch item summary preview for fetched orders
            if (!empty($orders)) {
                $orderIds = array_column($orders, 'id');
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $itemStmt = $db->prepare("
                    SELECT order_id, product_name, quantity, line_total
                    FROM order_items
                    WHERE order_id IN ({$placeholders})
                    ORDER BY id ASC
                ");
                $itemStmt->execute($orderIds);
                $allLineItems = $itemStmt->fetchAll();

                $itemsByOrder = [];
                foreach ($allLineItems as $li) {
                    $itemsByOrder[(int)$li['order_id']][] = $li;
                }

                foreach ($orders as &$ord) {
                    $oid = (int) $ord['id'];
                    $liList = $itemsByOrder[$oid] ?? [];
                    $ord['items_count'] = count($liList);
                    $summaryParts = [];
                    foreach ($liList as $li) {
                        $summaryParts[] = "{$li['quantity']}x {$li['product_name']}";
                    }
                    $ord['items_summary'] = !empty($summaryParts) ? implode(', ', $summaryParts) : 'No items';
                }
                unset($ord);
            }

            send_json(true, 'Orders retrieved successfully', [
                'orders' => $orders,
                'kpi' => [
                    'pending_count'       => (int) ($kpi['pending_count'] ?? 0),
                    'dispatched_count'    => (int) ($kpi['dispatched_count'] ?? 0),
                    'delivered_count'     => (int) ($kpi['delivered_count'] ?? 0),
                    'unremitted_cod_cash' => (float) ($kpi['unremitted_cod_cash'] ?? 0.00)
                ],
                'pagination' => [
                    'total'       => $totalCount,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => $totalPages
                ]
            ]);
            break;

        // =====================================================================
        // 3. GET ORDER DETAILS (Line items, notes, cod tracking & RBAC actions)
        // =====================================================================
        case 'get_order_details':
            if (!has_permission('orders_view')) {
                send_json(false, 'Access denied: Insufficient permissions (orders_view required)', null, [], 403);
            }

            $orderId = (int) ($input['id'] ?? 0);
            if ($orderId <= 0) {
                send_json(false, 'A valid Order ID is required.', null, ['id' => 'Invalid order ID'], 422);
            }

            // 1. Fetch Order Master
            $stmtOrder = $db->prepare("
                SELECT 
                    o.*,
                    u.full_name AS creator_name,
                    u.username AS creator_username,
                    r.name AS creator_role
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.id
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE o.id = :id AND o.deleted_at IS NULL
            ");
            $stmtOrder->execute([':id' => $orderId]);
            $order = $stmtOrder->fetch();

            if (!$order) {
                send_json(false, 'Order not found or has been removed.', null, [], 404);
            }

            // 2. Fetch Line Items
            $stmtItems = $db->prepare("
                SELECT 
                    oi.*,
                    p.sku,
                    p.current_stock
                FROM order_items oi
                LEFT JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = :id
                ORDER BY oi.id ASC
            ");
            $stmtItems->execute([':id' => $orderId]);
            $items = $stmtItems->fetchAll();

            // 3. Fetch COD Payment Details
            $stmtCod = $db->prepare("
                SELECT 
                    cp.*,
                    ru.full_name AS remitted_to_user_name,
                    ru.username AS remitted_to_username
                FROM cod_payments cp
                LEFT JOIN users ru ON cp.remitted_to_user_id = ru.id
                WHERE cp.order_id = :id
            ");
            $stmtCod->execute([':id' => $orderId]);
            $codPayment = $stmtCod->fetch();

            // 4. Fetch Order Communication Notes (Chronological descending)
            $stmtNotes = $db->prepare("
                SELECT 
                    onote.id,
                    onote.order_id,
                    onote.user_id,
                    onote.note,
                    onote.created_at,
                    u.full_name AS author_name,
                    u.username AS author_username,
                    r.label AS author_role
                FROM order_notes onote
                LEFT JOIN users u ON onote.user_id = u.id
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE onote.order_id = :id
                ORDER BY onote.created_at DESC, onote.id DESC
            ");
            $stmtNotes->execute([':id' => $orderId]);
            $notes = $stmtNotes->fetchAll();

            // 5. Determine Permitted Actions for the Current User
            $currentStatus = $order['order_status'];
            $paymentStatus = $order['payment_status'];
            $permittedActions = [];

            if ($currentStatus === ORDER_STATUS_CONFIRMED || $currentStatus === ORDER_STATUS_PENDING) {
                if (has_permission('orders_update_status')) {
                    $permittedActions[] = 'dispatch';
                }
                if (has_permission('orders_cancel')) {
                    $permittedActions[] = 'cancel';
                }
            } elseif ($currentStatus === ORDER_STATUS_DISPATCHED) {
                if (has_permission('orders_update_status')) {
                    $permittedActions[] = 'deliver';
                    $permittedActions[] = 'return';
                }
            } elseif ($currentStatus === ORDER_STATUS_DELIVERED) {
                if ($paymentStatus === PAYMENT_STATUS_COLLECTED && has_permission('orders_remit_cod')) {
                    $permittedActions[] = 'remit';
                }
            }

            if (has_permission('orders_view')) {
                $permittedActions[] = 'add_note';
            }

            send_json(true, 'Order details retrieved successfully', [
                'order'             => $order,
                'items'             => $items,
                'cod_payment'       => $codPayment,
                'notes'             => $notes,
                'permitted_actions' => $permittedActions
            ]);
            break;

        // =====================================================================
        // 4. UPDATE COD ORDER STATUS (State Machine Transitions)
        // =====================================================================
        case 'update_status':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            require_csrf($input);

            $orderId = (int) ($input['id'] ?? $input['order_id'] ?? 0);
            $targetStatus = strtoupper(trim((string) ($input['status'] ?? '')));
            $reason = trim((string) ($input['reason'] ?? $input['cancellation_reason'] ?? ''));

            if ($orderId <= 0) {
                send_json(false, 'A valid Order ID is required.', null, ['id' => 'Invalid order ID'], 422);
            }

            $allowedStatuses = [
                ORDER_STATUS_DISPATCHED,
                ORDER_STATUS_DELIVERED,
                'REMITTED',
                ORDER_STATUS_CANCELLED,
                ORDER_STATUS_RETURNED
            ];
            if (!in_array($targetStatus, $allowedStatuses, true)) {
                send_json(false, 'Invalid target status requested.', null, ['status' => 'Unsupported target status'], 422);
            }

            $db->beginTransaction();

            try {
                // Lock target order
                $stmtLock = $db->prepare("
                    SELECT * 
                    FROM orders 
                    WHERE id = :id AND deleted_at IS NULL 
                    FOR UPDATE
                ");
                $stmtLock->execute([':id' => $orderId]);
                $order = $stmtLock->fetch();

                if (!$order) {
                    $db->rollBack();
                    send_json(false, 'Order not found or has been removed.', null, [], 404);
                }

                $currentStatus = $order['order_status'];
                $currentPaymentStatus = $order['payment_status'];

                // Enforce Valid State Transitions & Specific RBAC Guards
                switch ($targetStatus) {
                    // -------------------------------------------------------------
                    // Transition 0: PENDING -> CONFIRMED
                    // -------------------------------------------------------------
                    case ORDER_STATUS_CONFIRMED:
                        if ($currentStatus !== ORDER_STATUS_PENDING) {
                            $db->rollBack();
                            send_json(false, "Cannot confirm order. Current status is '{$currentStatus}'.", null, [], 422);
                        }

                        if (!has_permission('orders_update_status')) {
                            $db->rollBack();
                            send_json(false, 'Access denied: Insufficient permissions (orders_update_status required)', null, [], 403);
                        }

                        $stmtUpdate = $db->prepare("
                            UPDATE orders 
                            SET order_status = :status, updated_at = NOW() 
                            WHERE id = :id
                        ");
                        $stmtUpdate->execute([
                            ':status' => ORDER_STATUS_CONFIRMED,
                            ':id'     => $orderId
                        ]);

                        $staffDisplayName = !empty($currentUser['full_name']) ? $currentUser['full_name'] : $currentUser['username'];
                        $noteText = 'Order confirmed by ' . $staffDisplayName . '.';
                        $stmtNote = $db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, :note, NOW())");
                        $stmtNote->execute([':oid' => $orderId, ':uid' => $currentUser['id'], ':note' => $noteText]);

                        audit_log('orders', 'STATUS_CONFIRMED', $orderId, $order, [
                            'order_status' => ORDER_STATUS_CONFIRMED
                        ]);
                        break;

                    // -------------------------------------------------------------
                    // Transition 1: CONFIRMED / PENDING -> DISPATCHED
                    // -------------------------------------------------------------
                    case ORDER_STATUS_DISPATCHED:
                        if ($currentStatus !== ORDER_STATUS_CONFIRMED && $currentStatus !== ORDER_STATUS_PENDING) {
                            $db->rollBack();
                            send_json(false, "Cannot dispatch order. Current status is '{$currentStatus}'.", null, [], 422);
                        }

                        if (!has_permission('orders_update_status')) {
                            $db->rollBack();
                            send_json(false, 'Access denied: Insufficient permissions (orders_update_status required)', null, [], 403);
                        }

                        $stmtUpdate = $db->prepare("
                            UPDATE orders 
                            SET order_status = :status, dispatched_at = NOW(), updated_at = NOW() 
                            WHERE id = :id
                        ");
                        $stmtUpdate->execute([
                            ':status' => ORDER_STATUS_DISPATCHED,
                            ':id'     => $orderId
                        ]);

                        // Append note
                        $noteText = 'Order dispatched for delivery by ' . $currentUser['username'] . '.';
                        $stmtNote = $db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, :note, NOW())");
                        $stmtNote->execute([':oid' => $orderId, ':uid' => $currentUser['id'], ':note' => $noteText]);

                        audit_log('orders', 'STATUS_DISPATCHED', $orderId, $order, [
                            'order_status'  => ORDER_STATUS_DISPATCHED,
                            'dispatched_at' => date('Y-m-d H:i:s')
                        ]);
                        break;

                    // -------------------------------------------------------------
                    // Transition 2: DISPATCHED -> DELIVERED (Cash Collection)
                    // -------------------------------------------------------------
                    case ORDER_STATUS_DELIVERED:
                        if ($currentStatus !== ORDER_STATUS_DISPATCHED) {
                            $db->rollBack();
                            send_json(false, "Cannot deliver order. Order must be 'DISPATCHED' first (Current: '{$currentStatus}').", null, [], 422);
                        }

                        if (!has_permission('orders_update_status')) {
                            $db->rollBack();
                            send_json(false, 'Access denied: Insufficient permissions (orders_update_status required)', null, [], 403);
                        }

                        $collectedBy = trim((string) ($input['collected_by'] ?? ''));
                        if ($collectedBy === '') {
                            $collectedBy = !empty($currentUser['full_name']) ? $currentUser['full_name'] : $currentUser['username'];
                        }

                        $amountCollected = isset($input['amount_collected']) && is_numeric($input['amount_collected'])
                            ? round((float) $input['amount_collected'], 2)
                            : (float) $order['total_amount'];

                        $stmtUpdate = $db->prepare("
                            UPDATE orders 
                            SET order_status = :status, payment_status = :pay_status, delivered_at = NOW(), updated_at = NOW() 
                            WHERE id = :id
                        ");
                        $stmtUpdate->execute([
                            ':status'     => ORDER_STATUS_DELIVERED,
                            ':pay_status' => PAYMENT_STATUS_COLLECTED,
                            ':id'         => $orderId
                        ]);

                        $stmtCodUpdate = $db->prepare("
                            UPDATE cod_payments 
                            SET status = 'COLLECTED', amount_collected = :amt, collected_by = :collector, collected_at = NOW() 
                            WHERE order_id = :id
                        ");
                        $stmtCodUpdate->execute([
                            ':amt'       => $amountCollected,
                            ':collector' => $collectedBy,
                            ':id'        => $orderId
                        ]);

                        $noteText = 'Order marked as DELIVERED. COD Cash Collected: ' . APP_CURRENCY . number_format($amountCollected, 2) . ' by ' . $collectedBy . '.';
                        $stmtNote = $db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, :note, NOW())");
                        $stmtNote->execute([':oid' => $orderId, ':uid' => $currentUser['id'], ':note' => $noteText]);

                        audit_log('orders', 'STATUS_DELIVERED', $orderId, $order, [
                            'order_status'     => ORDER_STATUS_DELIVERED,
                            'payment_status'   => PAYMENT_STATUS_COLLECTED,
                            'amount_collected' => $amountCollected,
                            'collected_by'     => $collectedBy
                        ]);
                        break;

                    // -------------------------------------------------------------
                    // Transition 3: DELIVERED -> REMITTED (Manager / Admin Cash Remittance)
                    // -------------------------------------------------------------
                    case 'REMITTED':
                        if ($currentStatus !== ORDER_STATUS_DELIVERED) {
                            $db->rollBack();
                            send_json(false, "Cannot remit cash. Order must be 'DELIVERED' and cash collected first.", null, [], 422);
                        }

                        if ($currentPaymentStatus !== PAYMENT_STATUS_COLLECTED) {
                            $db->rollBack();
                            send_json(false, "Cannot remit payment. Current payment status is '{$currentPaymentStatus}' (COLLECTED required).", null, [], 422);
                        }

                        // Strict RBAC: Admin & Store Manager Only. Cashier forbidden!
                        if (!has_permission('orders_remit_cod')) {
                            $db->rollBack();
                            send_json(false, 'Access denied: Only Business Owners (Admin) and Store Managers have authority to confirm COD remittance.', null, [], 403);
                        }

                        $stmtUpdate = $db->prepare("
                            UPDATE orders 
                            SET payment_status = :pay_status, updated_at = NOW() 
                            WHERE id = :id
                        ");
                        $stmtUpdate->execute([
                            ':pay_status' => PAYMENT_STATUS_REMITTED,
                            ':id'         => $orderId
                        ]);

                        $stmtCodUpdate = $db->prepare("
                            UPDATE cod_payments 
                            SET status = 'REMITTED', remitted_to_user_id = :uid, remitted_at = NOW() 
                            WHERE order_id = :id
                        ");
                        $stmtCodUpdate->execute([
                            ':uid' => $currentUser['id'],
                            ':id'  => $orderId
                        ]);

                        $noteText = 'COD cash remittance confirmed and received by ' . $currentUser['full_name'] . ' (' . $currentUser['username'] . ').';
                        $stmtNote = $db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, :note, NOW())");
                        $stmtNote->execute([':oid' => $orderId, ':uid' => $currentUser['id'], ':note' => $noteText]);

                        audit_log('orders', 'STATUS_REMITTED', $orderId, $order, [
                            'payment_status'      => PAYMENT_STATUS_REMITTED,
                            'remitted_to_user_id' => $currentUser['id'],
                            'remitted_at'         => date('Y-m-d H:i:s')
                        ]);
                        break;

                    // -------------------------------------------------------------
                    // Transition 4: CONFIRMED -> CANCELLED (Pre-dispatch stock restoration)
                    // -------------------------------------------------------------
                    case ORDER_STATUS_CANCELLED:
                        if ($currentStatus !== ORDER_STATUS_CONFIRMED && $currentStatus !== ORDER_STATUS_PENDING) {
                            $db->rollBack();
                            send_json(false, "Cannot cancel order. Cancellation is only allowed before dispatch. Use 'RETURNED' for post-dispatch returns.", null, [], 422);
                        }

                        if (!has_permission('orders_cancel')) {
                            $db->rollBack();
                            send_json(false, 'Access denied: Insufficient permissions (orders_cancel required)', null, [], 403);
                        }

                        $cancelReason = $reason !== '' ? $reason : 'Order cancelled before dispatch';

                        // Restore inventory for each line item
                        $stmtItems = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = :id");
                        $stmtItems->execute([':id' => $orderId]);
                        $orderItems = $stmtItems->fetchAll();

                        $stmtProdLock = $db->prepare("SELECT current_stock FROM products WHERE id = :id FOR UPDATE");
                        $stmtRestock = $db->prepare("UPDATE products SET current_stock = current_stock + :qty, updated_at = NOW() WHERE id = :id");
                        $stmtMovement = $db->prepare("
                            INSERT INTO inventory_movements (
                                product_id, user_id, movement_type, quantity, stock_before, stock_after,
                                reference_type, reference_id, notes, created_at
                            ) VALUES (
                                :product_id, :user_id, 'RETURN', :quantity, :stock_before, :stock_after,
                                'ORDER', :reference_id, :notes, NOW()
                            )
                        ");

                        foreach ($orderItems as $item) {
                            $pid = (int) $item['product_id'];
                            $qty = (int) $item['quantity'];

                            $stmtProdLock->execute([':id' => $pid]);
                            $stockBefore = (int) $stmtProdLock->fetchColumn();

                            $stmtRestock->execute([':qty' => $qty, ':id' => $pid]);
                            $stockAfter = $stockBefore + $qty;

                            $stmtMovement->execute([
                                ':product_id'   => $pid,
                                ':user_id'      => $currentUser['id'],
                                ':quantity'     => $qty,
                                ':stock_before' => $stockBefore,
                                ':stock_after'  => $stockAfter,
                                ':reference_id' => $orderId,
                                ':notes'        => 'Order cancelled stock restoration: ' . $order['order_number']
                            ]);
                        }

                        // Mark COD payment failed
                        $stmtCodUpdate = $db->prepare("UPDATE cod_payments SET status = 'FAILED', notes = 'Order cancelled prior to dispatch' WHERE order_id = :id");
                        $stmtCodUpdate->execute([':id' => $orderId]);

                        // Update order record
                        $stmtUpdate = $db->prepare("
                            UPDATE orders 
                            SET order_status = :status, payment_status = :pay_status, cancelled_at = NOW(),
                                cancellation_reason = :reason, updated_at = NOW() 
                            WHERE id = :id
                        ");
                        $stmtUpdate->execute([
                            ':status'     => ORDER_STATUS_CANCELLED,
                            ':pay_status' => PAYMENT_STATUS_FAILED,
                            ':reason'     => $cancelReason,
                            ':id'         => $orderId
                        ]);

                        $noteText = 'Order CANCELLED. Reason: ' . $cancelReason . '. Reserved stock returned to inventory.';
                        $stmtNote = $db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, :note, NOW())");
                        $stmtNote->execute([':oid' => $orderId, ':uid' => $currentUser['id'], ':note' => $noteText]);

                        audit_log('orders', 'STATUS_CANCELLED', $orderId, $order, [
                            'order_status'        => ORDER_STATUS_CANCELLED,
                            'cancellation_reason' => $cancelReason
                        ]);
                        break;

                    // -------------------------------------------------------------
                    // Transition 5: DISPATCHED -> RETURNED (Customer/Courier delivery failure)
                    // -------------------------------------------------------------
                    case ORDER_STATUS_RETURNED:
                        if ($currentStatus !== ORDER_STATUS_DISPATCHED) {
                            $db->rollBack();
                            send_json(false, "Cannot process return. Order must be 'DISPATCHED' to be marked as returned (Current: '{$currentStatus}').", null, [], 422);
                        }

                        if (!has_permission('orders_update_status')) {
                            $db->rollBack();
                            send_json(false, 'Access denied: Insufficient permissions (orders_update_status required)', null, [], 403);
                        }

                        $returnReason = $reason !== '' ? $reason : 'Returned by courier / rejected by customer';

                        // Restore stock via RETURN movement
                        $stmtItems = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = :id");
                        $stmtItems->execute([':id' => $orderId]);
                        $orderItems = $stmtItems->fetchAll();

                        $stmtProdLock = $db->prepare("SELECT current_stock FROM products WHERE id = :id FOR UPDATE");
                        $stmtRestock = $db->prepare("UPDATE products SET current_stock = current_stock + :qty, updated_at = NOW() WHERE id = :id");
                        $stmtMovement = $db->prepare("
                            INSERT INTO inventory_movements (
                                product_id, user_id, movement_type, quantity, stock_before, stock_after,
                                reference_type, reference_id, notes, created_at
                            ) VALUES (
                                :product_id, :user_id, 'RETURN', :quantity, :stock_before, :stock_after,
                                'ORDER', :reference_id, :notes, NOW()
                            )
                        ");

                        foreach ($orderItems as $item) {
                            $pid = (int) $item['product_id'];
                            $qty = (int) $item['quantity'];

                            $stmtProdLock->execute([':id' => $pid]);
                            $stockBefore = (int) $stmtProdLock->fetchColumn();

                            $stmtRestock->execute([':qty' => $qty, ':id' => $pid]);
                            $stockAfter = $stockBefore + $qty;

                            $stmtMovement->execute([
                                ':product_id'   => $pid,
                                ':user_id'      => $currentUser['id'],
                                ':quantity'     => $qty,
                                ':stock_before' => $stockBefore,
                                ':stock_after'  => $stockAfter,
                                ':reference_id' => $orderId,
                                ':notes'        => 'Order returned stock restoration: ' . $order['order_number']
                            ]);
                        }

                        // Mark COD payment failed
                        $stmtCodUpdate = $db->prepare("UPDATE cod_payments SET status = 'FAILED', notes = 'Customer returned package' WHERE order_id = :id");
                        $stmtCodUpdate->execute([':id' => $orderId]);

                        // Update order record
                        $stmtUpdate = $db->prepare("
                            UPDATE orders 
                            SET order_status = :status, payment_status = :pay_status,
                                cancellation_reason = :reason, updated_at = NOW() 
                            WHERE id = :id
                        ");
                        $stmtUpdate->execute([
                            ':status'     => ORDER_STATUS_RETURNED,
                            ':pay_status' => PAYMENT_STATUS_FAILED,
                            ':reason'     => $returnReason,
                            ':id'         => $orderId
                        ]);

                        $noteText = 'Order marked as RETURNED. Reason: ' . $returnReason . '. Stock returned to inventory.';
                        $stmtNote = $db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, :note, NOW())");
                        $stmtNote->execute([':oid' => $orderId, ':uid' => $currentUser['id'], ':note' => $noteText]);

                        audit_log('orders', 'STATUS_RETURNED', $orderId, $order, [
                            'order_status'        => ORDER_STATUS_RETURNED,
                            'cancellation_reason' => $returnReason
                        ]);
                        break;
                }

                $db->commit();

                send_json(true, "Order status updated to {$targetStatus} successfully.", [
                    'id'           => $orderId,
                    'order_status' => $targetStatus
                ]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            break;

        // =====================================================================
        // 4b. ASSIGN COURIER WAYBILL & DISPATCH (PHASE 6)
        // =====================================================================
        case 'assign_courier':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            require_csrf($input);

            if (!has_permission('orders_update_status')) {
                send_json(false, 'Access denied: Insufficient permissions (orders_update_status required)', null, [], 403);
            }

            $orderId = (int) ($input['id'] ?? $input['order_id'] ?? 0);
            $courierName = trim((string) ($input['courier_name'] ?? ''));
            $trackingNumber = trim((string) ($input['tracking_number'] ?? ''));
            $autoDispatch = !isset($input['auto_dispatch']) || !empty($input['auto_dispatch']);

            if ($orderId <= 0) {
                send_json(false, 'A valid Order ID is required.', null, ['id' => 'Invalid order ID'], 422);
            }

            if ($courierName === '') {
                send_json(false, 'Courier name is required.', null, ['courier_name' => 'Required'], 422);
            }

            if ($trackingNumber === '') {
                send_json(false, 'Courier tracking number is required.', null, ['tracking_number' => 'Required'], 422);
            }

            $db->beginTransaction();

            try {
                $stmtLock = $db->prepare("SELECT * FROM orders WHERE id = :id AND deleted_at IS NULL FOR UPDATE");
                $stmtLock->execute([':id' => $orderId]);
                $order = $stmtLock->fetch();

                if (!$order) {
                    $db->rollBack();
                    send_json(false, 'Order not found or has been removed.', null, [], 404);
                }

                $newStatus = $order['order_status'];
                $newShipStatus = $order['shipping_status'];
                $shouldDispatch = ($autoDispatch && in_array($order['order_status'], ['PENDING', 'CONFIRMED'], true));

                if ($shouldDispatch) {
                    $newStatus = ORDER_STATUS_DISPATCHED;
                    $newShipStatus = 'IN_TRANSIT';
                }

                $stmtUpdate = $db->prepare("
                    UPDATE orders
                    SET courier_name = :courier_name,
                        tracking_number = :tracking_number,
                        order_status = :status,
                        shipping_status = :ship_status,
                        dispatched_at = CASE WHEN :dispatched = 1 AND dispatched_at IS NULL THEN NOW() ELSE dispatched_at END,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    ':courier_name'    => $courierName,
                    ':tracking_number' => $trackingNumber,
                    ':status'          => $newStatus,
                    ':ship_status'     => $newShipStatus,
                    ':dispatched'      => ($newStatus === ORDER_STATUS_DISPATCHED ? 1 : 0),
                    ':id'              => $orderId
                ]);

                $noteText = "Courier assigned: {$courierName} (Waybill: {$trackingNumber})" . ($shouldDispatch ? " - Order marked as DISPATCHED." : ".") . " by " . $currentUser['username'];
                $stmtNote = $db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, :note, NOW())");
                $stmtNote->execute([':oid' => $orderId, ':uid' => $currentUser['id'], ':note' => $noteText]);

                audit_log('orders', 'ASSIGN_COURIER', $orderId, $order, [
                    'courier_name'    => $courierName,
                    'tracking_number' => $trackingNumber,
                    'order_status'    => $newStatus
                ]);

                $db->commit();

                send_json(true, 'Courier waybill assigned successfully.', [
                    'id'              => $orderId,
                    'courier_name'    => $courierName,
                    'tracking_number' => $trackingNumber,
                    'order_status'    => $newStatus,
                    'shipping_status' => $newShipStatus
                ]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            break;

        // =====================================================================
        // 5. ADD ORDER COMMUNICATION NOTE
        // =====================================================================
        case 'add_note':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            if (!has_permission('orders_view')) {
                send_json(false, 'Access denied: Insufficient permissions (orders_view required)', null, [], 403);
            }

            require_csrf($input);

            $orderId = (int) ($input['id'] ?? $input['order_id'] ?? 0);
            $note = trim((string) ($input['note'] ?? ''));

            $errors = [];
            if ($orderId <= 0) {
                $errors['order_id'] = 'A valid Order ID is required.';
            }

            if ($note === '') {
                $errors['note'] = 'Note text cannot be empty.';
            } elseif (mb_strlen($note) > 1000) {
                $errors['note'] = 'Note text cannot exceed 1000 characters.';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed. Please correct the highlighted errors.', null, $errors, 422);
            }

            // Verify order exists
            $stmtOrder = $db->prepare("SELECT id FROM orders WHERE id = :id AND deleted_at IS NULL");
            $stmtOrder->execute([':id' => $orderId]);
            if (!$stmtOrder->fetch()) {
                send_json(false, 'Order not found or has been removed.', null, [], 404);
            }

            $stmtInsert = $db->prepare("
                INSERT INTO order_notes (order_id, user_id, note, created_at)
                VALUES (:order_id, :user_id, :note, NOW())
            ");
            $stmtInsert->execute([
                ':order_id' => $orderId,
                ':user_id'  => $currentUser['id'],
                ':note'     => $note
            ]);
            $noteId = (int) $db->lastInsertId();

            audit_log('orders', 'ADD_NOTE', $orderId, null, [
                'note_id' => $noteId,
                'note'    => mb_substr($note, 0, 100)
            ]);

            // Retrieve inserted note with author metadata
            $stmtFetch = $db->prepare("
                SELECT 
                    onote.id,
                    onote.order_id,
                    onote.user_id,
                    onote.note,
                    onote.created_at,
                    u.full_name AS author_name,
                    u.username AS author_username,
                    r.label AS author_role
                FROM order_notes onote
                LEFT JOIN users u ON onote.user_id = u.id
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE onote.id = :id
            ");
            $stmtFetch->execute([':id' => $noteId]);
            $newNote = $stmtFetch->fetch();

            send_json(true, 'Note added to order successfully.', $newNote, [], 201);
            break;

        default:
            send_json(false, 'Invalid or unrecognized action requested.', null, ['action' => $action], 400);
            break;
    }
} catch (PDOException $e) {
    error_log('[Orders Handler Database Error] ' . $e->getMessage());
    send_json(false, 'A database error occurred while processing the order.', null, [], 500);
} catch (Throwable $e) {
    error_log('[Orders Handler System Error] ' . $e->getMessage());
    send_json(false, 'An unexpected server error occurred.', null, [], 500);
}
