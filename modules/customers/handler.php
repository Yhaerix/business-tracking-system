<?php
/**
 * Customers & Storefront Registered Users API Handler
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

function send_json(bool $success, string $message, $data = null, array $errors = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
        'errors'  => $errors
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_request_data(): array
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $_POST;
    }
    return $_GET;
}

function require_csrf(array $input): void
{
    $token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verify_csrf_token($token)) {
        send_json(false, 'Security token invalid or expired. Please refresh the page.', null, ['csrf' => 'Invalid CSRF token'], 403);
    }
}

// Session Authentication Guard
require_login();

// Allow Admin, Manager, and staff with view privileges
if (!has_permission('users_manage') && !has_permission('orders_view')) {
    send_json(false, 'Access denied: Insufficient privileges to view registered users.', null, [], 403);
}

$input = get_request_data();
$action = trim((string) ($input['action'] ?? ''));
$db = get_db();
$currentUser = current_user();

try {
    switch ($action) {
        // =====================================================================
        // 1. LIST REGISTERED USERS
        // =====================================================================
        case 'list_customers':
            $search = trim((string) ($input['search'] ?? ''));
            $status = trim((string) ($input['status'] ?? 'all'));
            $page = max(1, (int) ($input['page'] ?? 1));
            $limit = min(100, max(1, (int) ($input['limit'] ?? 15)));
            $offset = ($page - 1) * $limit;

            $allowedSorts = ['name', 'created_at', 'total_orders', 'wallet_balance'];
            $sortBy = in_array($input['sort_by'] ?? '', $allowedSorts, true) ? $input['sort_by'] : 'created_at';
            $sortDir = strtoupper($input['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            $where = ['c.deleted_at IS NULL'];
            $params = [];

            if ($search !== '') {
                $where[] = '(c.name LIKE :s_name OR c.email LIKE :s_email OR c.phone LIKE :s_phone)';
                $sTerm = '%' . $search . '%';
                $params[':s_name'] = $sTerm;
                $params[':s_email'] = $sTerm;
                $params[':s_phone'] = $sTerm;
            }

            if ($status === 'active' || $status === 'inactive') {
                $where[] = 'c.status = :status';
                $params[':status'] = $status;
            }

            $whereSql = implode(' AND ', $where);

            // Total count
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM customers c WHERE {$whereSql}");
            $stmtCount->execute($params);
            $total = (int) $stmtCount->fetchColumn();

            // Aggregate KPIs
            $stmtKpi = $db->query("
                SELECT 
                    COUNT(*) AS total_registered,
                    SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN c.status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
                    COALESCE(SUM(COALESCE(cw.coin_balance, c.wallet_balance, 0.00)), 0.00) AS total_coins_held
                FROM customers c
                LEFT JOIN customer_wallets cw ON cw.customer_id = c.id
                WHERE c.deleted_at IS NULL
            ");
            $kpiStats = $stmtKpi->fetch() ?: [
                'total_registered' => 0,
                'active_count'     => 0,
                'inactive_count'   => 0,
                'total_coins_held' => 0.00
            ];

            // Order count and spend across all customers
            $stmtOrderTotal = $db->query("
                SELECT COUNT(*) as total_cust_orders, COALESCE(SUM(total_amount), 0.00) as total_cust_sales
                FROM orders 
                WHERE customer_id IS NOT NULL AND deleted_at IS NULL
            ");
            $orderTotalRow = $stmtOrderTotal->fetch() ?: ['total_cust_orders' => 0, 'total_cust_sales' => 0.00];
            $kpiStats['total_orders_placed'] = (int)$orderTotalRow['total_cust_orders'];
            $kpiStats['total_sales_value'] = (float)$orderTotalRow['total_cust_sales'];

            // Customer Rows with aggregated order stats
            $sortColumn = match ($sortBy) {
                'name'           => 'c.name',
                'total_orders'   => 'total_orders',
                'wallet_balance' => 'wallet_balance',
                default          => 'c.created_at'
            };

            $query = "
                SELECT c.id, c.name, c.email, c.phone, 
                       COALESCE(cw.coin_balance, c.wallet_balance, 0.00) AS wallet_balance,
                       c.status, c.last_login_at, c.created_at,
                       COUNT(DISTINCT o.id) AS total_orders,
                       COALESCE(SUM(CASE WHEN o.deleted_at IS NULL THEN o.total_amount ELSE 0 END), 0.00) AS total_spent,
                       (SELECT COUNT(*) FROM customer_messages cm WHERE cm.customer_id = c.id AND cm.sender_type = 'customer' AND cm.is_read = 0) AS unread_messages
                FROM customers c
                LEFT JOIN customer_wallets cw ON cw.customer_id = c.id
                LEFT JOIN orders o ON o.customer_id = c.id AND o.deleted_at IS NULL
                WHERE {$whereSql}
                GROUP BY c.id
                ORDER BY {$sortColumn} {$sortDir}
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $db->prepare($query);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $customers = $stmt->fetchAll();

            send_json(true, 'Registered users retrieved successfully', [
                'customers'  => $customers,
                'stats'      => $kpiStats,
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => (int) ceil($total / $limit)
                ]
            ]);
            break;

        // =====================================================================
        // 2. GET CUSTOMER DETAILS & DRAWER PAYLOAD
        // =====================================================================
        case 'get_customer':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Invalid customer ID provided.', null, [], 422);
            }

            $stmtCust = $db->prepare("
                SELECT c.id, c.name, c.email, c.phone, 
                       COALESCE(cw.coin_balance, c.wallet_balance, 0.00) AS wallet_balance,
                       COALESCE(cw.is_frozen, 0) AS is_frozen,
                       c.status, c.notes, c.last_login_at, c.created_at, c.updated_at,
                       COUNT(DISTINCT o.id) AS total_orders,
                       COALESCE(SUM(CASE WHEN o.deleted_at IS NULL THEN o.total_amount ELSE 0 END), 0.00) AS total_spent
                FROM customers c
                LEFT JOIN customer_wallets cw ON cw.customer_id = c.id
                LEFT JOIN orders o ON o.customer_id = c.id AND o.deleted_at IS NULL
                WHERE c.id = :id AND c.deleted_at IS NULL
                GROUP BY c.id
                LIMIT 1
            ");
            $stmtCust->execute([':id' => $id]);
            $customer = $stmtCust->fetch();

            if (!$customer) {
                send_json(false, 'Customer record not found.', null, [], 404);
            }

            // Customer Addresses
            $stmtAddr = $db->prepare("
                SELECT id, address_line1, address_line2, city, province_state AS province, postal_code, is_default, created_at
                FROM customer_addresses
                WHERE customer_id = :id
                ORDER BY is_default DESC, id DESC
            ");
            $stmtAddr->execute([':id' => $id]);
            $addresses = $stmtAddr->fetchAll();

            // Recent Orders
            $stmtOrders = $db->prepare("
                SELECT id, order_number, total_amount, order_status, payment_status, shipping_status, created_at
                FROM orders
                WHERE customer_id = :id AND deleted_at IS NULL
                ORDER BY id DESC
                LIMIT 10
            ");
            $stmtOrders->execute([':id' => $id]);
            $recentOrders = $stmtOrders->fetchAll();

            // Wallet Transactions (if table exists)
            $walletTransactions = [];
            try {
                $stmtTx = $db->prepare("
                    SELECT id, type, amount, balance_after, reference, description, created_at
                    FROM wallet_transactions
                    WHERE customer_id = :id
                    ORDER BY id DESC
                    LIMIT 8
                ");
                $stmtTx->execute([':id' => $id]);
                $walletTransactions = $stmtTx->fetchAll();
            } catch (Throwable $e) {
                $walletTransactions = [];
            }

            send_json(true, 'Customer details retrieved', [
                'customer'            => $customer,
                'addresses'           => $addresses,
                'orders'              => $recentOrders,
                'wallet_transactions' => $walletTransactions
            ]);
            break;

        // =====================================================================
        // 3. TOGGLE CUSTOMER ACCOUNT STATUS (Active / Inactive)
        // =====================================================================
        case 'toggle_status':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('users_manage')) {
                send_json(false, 'Access denied: Admin permission required to manage account statuses.', null, [], 403);
            }
            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            $newStatus = trim((string) ($input['status'] ?? ''));

            if ($id <= 0 || !in_array($newStatus, ['active', 'inactive'], true)) {
                send_json(false, 'Invalid customer ID or status parameter.', null, [], 422);
            }

            $stmtCheck = $db->prepare("SELECT id, name, status FROM customers WHERE id = :id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id]);
            $existing = $stmtCheck->fetch();

            if (!$existing) {
                send_json(false, 'Customer account not found.', null, [], 404);
            }

            $oldStatus = $existing['status'];
            $stmtUpdate = $db->prepare("UPDATE customers SET status = :status, updated_at = NOW() WHERE id = :id");
            $stmtUpdate->execute([
                ':status' => $newStatus,
                ':id'     => $id
            ]);

            audit_log('customers', 'TOGGLE_STATUS', $id, ['status' => $oldStatus], ['status' => $newStatus]);

            send_json(true, "Customer account status updated to {$newStatus}.", [
                'id'         => $id,
                'new_status' => $newStatus
            ]);
            break;

        // =====================================================================
        // 4. UPDATE ADMIN NOTES
        // =====================================================================
        case 'update_notes':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }
            if (!has_permission('users_manage')) {
                send_json(false, 'Access denied.', null, [], 403);
            }
            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            $notes = trim((string) ($input['notes'] ?? ''));

            if ($id <= 0) {
                send_json(false, 'Invalid customer ID.', null, [], 422);
            }

            $stmt = $db->prepare("UPDATE customers SET notes = :notes, updated_at = NOW() WHERE id = :id");
            $stmt->execute([
                ':notes' => $notes !== '' ? $notes : null,
                ':id'    => $id
            ]);

            send_json(true, 'Customer notes updated successfully.');
            break;

        default:
            send_json(false, 'Unknown or missing action parameter.', null, [], 400);
            break;
    }
} catch (Throwable $e) {
    error_log('[Customers Handler Error] ' . $e->getMessage());
    send_json(false, 'An internal server error occurred while processing customer data.', null, ['error' => $e->getMessage()], 500);
}
