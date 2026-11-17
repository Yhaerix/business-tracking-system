<?php
/**
 * Invoices & Receipts Backend Handler
 * Small Business Sales and Expense Tracking System
 *
 * Actions:
 * - list_invoices (GET/POST, Permission: invoices_view)
 * - get_invoice   (GET/POST, Permission: invoices_view)
 * - void_invoice  (POST,     Permission: invoices_manage - Admin Only)
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
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verify_csrf_token((string) $token)) {
        send_json(false, 'Invalid or expired security token. Please refresh and try again.', null, [
            'csrf_token' => 'CSRF verification failed.'
        ], 403);
    }
}

/**
 * Resolve the full name of the authorized staff member who confirmed or processed the order
 */
function get_order_confirmed_by_name(PDO $db, int $orderId): string
{
    // 1. Check audit log for status confirmation or transitions
    $stmt = $db->prepare("
        SELECT u.full_name, u.username
        FROM audit_logs a
        INNER JOIN users u ON a.user_id = u.id
        WHERE a.module = 'orders'
          AND a.record_id = :order_id
          AND a.action IN ('STATUS_CONFIRMED', 'STATUS_DISPATCHED', 'ASSIGN_COURIER', 'STATUS_DELIVERED')
        ORDER BY a.id ASC
        LIMIT 1
    ");
    $stmt->execute([':order_id' => $orderId]);
    $row = $stmt->fetch();
    if ($row && (!empty($row['full_name']) || !empty($row['username']))) {
        return !empty($row['full_name']) ? trim((string) $row['full_name']) : trim((string) $row['username']);
    }

    // 2. Check order notes for staff author
    $stmtNotes = $db->prepare("
        SELECT u.full_name, u.username
        FROM order_notes n
        INNER JOIN users u ON n.user_id = u.id
        WHERE n.order_id = :order_id
        ORDER BY n.id ASC
        LIMIT 1
    ");
    $stmtNotes->execute([':order_id' => $orderId]);
    $row = $stmtNotes->fetch();
    if ($row && (!empty($row['full_name']) || !empty($row['username']))) {
        return !empty($row['full_name']) ? trim((string) $row['full_name']) : trim((string) $row['username']);
    }

    // 3. Check order creator user in orders table
    $stmtOrder = $db->prepare("
        SELECT u.full_name, u.username
        FROM orders o
        INNER JOIN users u ON o.user_id = u.id
        WHERE o.id = :order_id
    ");
    $stmtOrder->execute([':order_id' => $orderId]);
    $row = $stmtOrder->fetch();
    if ($row && (!empty($row['full_name']) || !empty($row['username']))) {
        return !empty($row['full_name']) ? trim((string) $row['full_name']) : trim((string) $row['username']);
    }

    return 'Store Manager';
}

// Ensure caller is authenticated
if (!is_logged_in()) {
    send_json(false, 'Authentication required.', null, [], 401);
}

$input = get_request_data();
$action = trim((string) ($input['action'] ?? ''));

if ($action === '') {
    send_json(false, 'Action parameter is required.', null, [], 400);
}

$db = Database::getConnection();

try {
    switch ($action) {
        // ======================================================================
        // ACTION: list_invoices
        // ======================================================================
        case 'list_invoices':
            if (!has_permission('invoices_view')) {
                send_json(false, 'Forbidden: You do not have permission to view invoices.', null, [], 403);
            }

            // Auto-generate invoice records for any orders that do not yet have an invoice
            $uninvoicedOrders = $db->query("
                SELECT o.id, o.order_status, o.total_amount, o.created_at, cp.status as cod_status
                FROM orders o
                LEFT JOIN cod_payments cp ON cp.order_id = o.id
                WHERE o.id NOT IN (SELECT order_id FROM invoices)
                ORDER BY o.id ASC
            ")->fetchAll();

            if (!empty($uninvoicedOrders)) {
                $year = date('Y');
                $stmtSeq = $db->prepare("
                    SELECT invoice_number 
                    FROM invoices 
                    WHERE invoice_number LIKE :prefix 
                    ORDER BY id DESC 
                    LIMIT 1
                ");
                $stmtSeq->execute([':prefix' => 'INV-' . $year . '-%']);
                $lastInv = $stmtSeq->fetch();
                $nextSeq = 1;
                if ($lastInv && !empty($lastInv['invoice_number']) && preg_match('/INV-\d{4}-(\d+)/', (string) $lastInv['invoice_number'], $m)) {
                    $nextSeq = ((int) $m[1]) + 1;
                }

                $stmtInsertInv = $db->prepare("
                    INSERT INTO invoices (
                        order_id, invoice_number, receipt_number, issued_date, amount_paid, status, void_reason, created_at
                    ) VALUES (
                        :order_id, :inv_num, :rcp_num, :issued_date, :amount_paid, :status, :void_reason, :created_at
                    )
                ");

                foreach ($uninvoicedOrders as $ord) {
                    $invNum = sprintf('INV-%s-%04d', $year, $nextSeq);
                    $rcpNum = sprintf('RCP-%s-%04d', $year, $nextSeq);
                    $nextSeq++;

                    $invStatus = 'ISSUED';
                    $amountPaid = 0.00;
                    $voidReason = null;
                    if ($ord['order_status'] === 'CANCELLED') {
                        $invStatus = 'VOID';
                        $voidReason = 'Order was cancelled prior to invoicing';
                    } elseif ($ord['order_status'] === 'DELIVERED' || in_array($ord['cod_status'] ?? '', ['COLLECTED', 'REMITTED'], true)) {
                        $invStatus = 'PAID';
                        $amountPaid = (float) $ord['total_amount'];
                    }

                    $issuedDate = !empty($ord['created_at']) ? substr((string) $ord['created_at'], 0, 10) : date('Y-m-d');
                    $stmtInsertInv->execute([
                        ':order_id'    => $ord['id'],
                        ':inv_num'     => $invNum,
                        ':rcp_num'     => $rcpNum,
                        ':issued_date' => $issuedDate,
                        ':amount_paid' => $amountPaid,
                        ':status'      => $invStatus,
                        ':void_reason' => $voidReason,
                        ':created_at'  => $ord['created_at'] ?: date('Y-m-d H:i:s')
                    ]);
                }
            }

            // Optional auto-sync existing invoices with current order/COD status
            $db->exec("
                UPDATE invoices i
                INNER JOIN orders o ON i.order_id = o.id
                LEFT JOIN cod_payments cp ON cp.order_id = o.id
                SET i.status = 'PAID', i.amount_paid = o.total_amount
                WHERE i.status = 'ISSUED' AND (o.order_status = 'DELIVERED' OR cp.status IN ('COLLECTED', 'REMITTED'))
            ");

            $db->exec("
                UPDATE invoices i
                INNER JOIN orders o ON i.order_id = o.id
                SET i.status = 'VOID', i.void_reason = COALESCE(i.void_reason, 'Associated order was cancelled')
                WHERE i.status != 'VOID' AND o.order_status = 'CANCELLED'
            ");

            $page = max(1, (int) ($input['page'] ?? 1));
            $perPage = max(1, min(100, (int) ($input['per_page'] ?? 15)));
            $offset = ($page - 1) * $perPage;

            $status = strtoupper(trim((string) ($input['status'] ?? 'ALL')));
            $search = trim((string) ($input['search'] ?? ''));
            $dateFrom = trim((string) ($input['date_from'] ?? ''));
            $dateTo = trim((string) ($input['date_to'] ?? ''));

            $whereClauses = ['1=1'];
            $params = [];

            if (in_array($status, ['ISSUED', 'PAID', 'VOID'], true)) {
                $whereClauses[] = 'i.status = :status';
                $params[':status'] = $status;
            }

            if ($search !== '') {
                $whereClauses[] = '(
                    i.invoice_number LIKE :s1 OR 
                    i.receipt_number LIKE :s2 OR 
                    o.order_number LIKE :s3 OR 
                    o.customer_name LIKE :s4 OR 
                    o.customer_phone LIKE :s5
                )';
                $term = "%{$search}%";
                $params[':s1'] = $term;
                $params[':s2'] = $term;
                $params[':s3'] = $term;
                $params[':s4'] = $term;
                $params[':s5'] = $term;
            }

            if ($dateFrom !== '') {
                $whereClauses[] = 'i.issued_date >= :date_from';
                $params[':date_from'] = $dateFrom;
            }

            if ($dateTo !== '') {
                $whereClauses[] = 'i.issued_date <= :date_to';
                $params[':date_to'] = $dateTo;
            }

            $whereSql = implode(' AND ', $whereClauses);

            // Total count query for pagination
            $countSql = "
                SELECT COUNT(*) as total 
                FROM invoices i
                INNER JOIN orders o ON i.order_id = o.id
                WHERE {$whereSql}
            ";
            $stmtCount = $db->prepare($countSql);
            $stmtCount->execute($params);
            $totalItems = (int) $stmtCount->fetchColumn();
            $totalPages = (int) ceil($totalItems / $perPage);

            // Fetch records
            $dataSql = "
                SELECT 
                    i.id,
                    i.order_id,
                    i.invoice_number,
                    i.receipt_number,
                    i.issued_date,
                    i.amount_paid,
                    i.status,
                    i.void_reason,
                    i.created_at,
                    o.order_number,
                    o.customer_name,
                    o.customer_phone,
                    o.total_amount,
                    o.order_status,
                    o.payment_method,
                    cp.status as cod_status
                FROM invoices i
                INNER JOIN orders o ON i.order_id = o.id
                LEFT JOIN cod_payments cp ON cp.order_id = o.id
                WHERE {$whereSql}
                ORDER BY i.id DESC
                LIMIT :limit OFFSET :offset
            ";
            $stmtData = $db->prepare($dataSql);
            foreach ($params as $k => $v) {
                $stmtData->bindValue($k, $v);
            }
            $stmtData->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmtData->execute();
            $items = $stmtData->fetchAll();

            // Overall Summary Metrics (across all records)
            $stmtSummary = $db->query("
                SELECT 
                    COUNT(*) as total_invoices,
                    COALESCE(SUM(CASE WHEN i.status != 'VOID' THEN o.total_amount ELSE 0 END), 0) as total_invoiced_amount,
                    COALESCE(SUM(CASE WHEN i.status = 'PAID' THEN 1 ELSE 0 END), 0) as paid_count,
                    COALESCE(SUM(CASE WHEN i.status = 'PAID' THEN i.amount_paid ELSE 0 END), 0) as paid_amount,
                    COALESCE(SUM(CASE WHEN i.status = 'ISSUED' THEN 1 ELSE 0 END), 0) as pending_count,
                    COALESCE(SUM(CASE WHEN i.status = 'ISSUED' THEN o.total_amount ELSE 0 END), 0) as pending_amount,
                    COALESCE(SUM(CASE WHEN i.status = 'VOID' THEN 1 ELSE 0 END), 0) as void_count
                FROM invoices i
                INNER JOIN orders o ON i.order_id = o.id
            ");
            $summary = $stmtSummary->fetch();

            send_json(true, 'Invoices retrieved successfully.', [
                'items'      => $items,
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total_items' => $totalItems,
                    'total_pages' => $totalPages
                ],
                'summary'    => [
                    'total_invoices'        => (int) ($summary['total_invoices'] ?? 0),
                    'total_invoiced_amount' => (float) ($summary['total_invoiced_amount'] ?? 0),
                    'paid_count'            => (int) ($summary['paid_count'] ?? 0),
                    'paid_amount'           => (float) ($summary['paid_amount'] ?? 0),
                    'pending_count'         => (int) ($summary['pending_count'] ?? 0),
                    'pending_amount'        => (float) ($summary['pending_amount'] ?? 0),
                    'void_count'            => (int) ($summary['void_count'] ?? 0)
                ]
            ]);
            break;

        // ======================================================================
        // ACTION: get_invoice
        // ======================================================================
        case 'get_invoice':
            if (!has_permission('invoices_view')) {
                send_json(false, 'Forbidden: You do not have permission to view invoices.', null, [], 403);
            }

            $invoiceId = isset($input['id']) && is_numeric($input['id']) ? (int) $input['id'] : null;
            $orderId = isset($input['order_id']) && is_numeric($input['order_id']) ? (int) $input['order_id'] : null;

            if ($invoiceId === null && $orderId === null) {
                send_json(false, 'An Invoice ID or Order ID is required.', null, [
                    'id' => 'Please provide either an id or order_id parameter.'
                ], 422);
            }

            // If order_id is provided, check if invoice exists or auto-generate
            if ($invoiceId === null && $orderId !== null) {
                $stmtCheck = $db->prepare("SELECT id FROM invoices WHERE order_id = :order_id");
                $stmtCheck->execute([':order_id' => $orderId]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
                    $invoiceId = (int) $existingId;
                } else {
                    // Atomic creation of invoice for order
                    $db->beginTransaction();

                    $stmtOrder = $db->prepare("SELECT * FROM orders WHERE id = :id FOR UPDATE");
                    $stmtOrder->execute([':id' => $orderId]);
                    $orderData = $stmtOrder->fetch();

                    if (!$orderData) {
                        $db->rollBack();
                        send_json(false, "Order #{$orderId} not found.", null, [], 404);
                    }

                    $year = date('Y');
                    $prefixInv = 'INV-' . $year . '-%';
                    $stmtSeq = $db->prepare("
                        SELECT invoice_number 
                        FROM invoices 
                        WHERE invoice_number LIKE :prefix 
                        ORDER BY id DESC 
                        LIMIT 1 
                        FOR UPDATE
                    ");
                    $stmtSeq->execute([':prefix' => $prefixInv]);
                    $lastInv = $stmtSeq->fetch();

                    $nextSeq = 1;
                    if ($lastInv && !empty($lastInv['invoice_number'])) {
                        if (preg_match('/INV-\d{4}-(\d+)/', (string) $lastInv['invoice_number'], $matches)) {
                            $nextSeq = ((int) $matches[1]) + 1;
                        }
                    }

                    $invNumber = sprintf('INV-%s-%04d', $year, $nextSeq);
                    $rcpNumber = sprintf('RCP-%s-%04d', $year, $nextSeq);

                    // Ensure unique collision safety
                    $stmtExists = $db->prepare("SELECT 1 FROM invoices WHERE invoice_number = :inv OR receipt_number = :rcp");
                    while (true) {
                        $stmtExists->execute([':inv' => $invNumber, ':rcp' => $rcpNumber]);
                        if (!$stmtExists->fetch()) {
                            break;
                        }
                        $nextSeq++;
                        $invNumber = sprintf('INV-%s-%04d', $year, $nextSeq);
                        $rcpNumber = sprintf('RCP-%s-%04d', $year, $nextSeq);
                    }

                    // Check COD payment status
                    $stmtCod = $db->prepare("SELECT status FROM cod_payments WHERE order_id = :order_id");
                    $stmtCod->execute([':order_id' => $orderId]);
                    $codRow = $stmtCod->fetch();
                    $codStatus = $codRow['status'] ?? 'PENDING';

                    $invStatus = 'ISSUED';
                    $amountPaid = 0.00;
                    $voidReason = null;

                    if ($orderData['order_status'] === 'CANCELLED') {
                        $invStatus = 'VOID';
                        $voidReason = 'Order was cancelled prior to invoicing';
                    } elseif ($orderData['order_status'] === 'DELIVERED' || in_array($codStatus, ['COLLECTED', 'REMITTED'], true)) {
                        $invStatus = 'PAID';
                        $amountPaid = (float) $orderData['total_amount'];
                    }

                    $issuedDate = !empty($orderData['created_at']) ? substr((string) $orderData['created_at'], 0, 10) : date('Y-m-d');

                    $stmtInsert = $db->prepare("
                        INSERT INTO invoices (
                            order_id, invoice_number, receipt_number, issued_date, amount_paid, status, void_reason, created_at
                        ) VALUES (
                            :order_id, :inv_num, :rcp_num, :issued_date, :amount_paid, :status, :void_reason, NOW()
                        )
                    ");
                    $stmtInsert->execute([
                        ':order_id'    => $orderId,
                        ':inv_num'     => $invNumber,
                        ':rcp_num'     => $rcpNumber,
                        ':issued_date' => $issuedDate,
                        ':amount_paid' => $amountPaid,
                        ':status'      => $invStatus,
                        ':void_reason' => $voidReason
                    ]);

                    $invoiceId = (int) $db->lastInsertId();

                    audit_log(
                        'invoices',
                        'GENERATE',
                        $invoiceId,
                        null,
                        [
                            'invoice_number' => $invNumber,
                            'receipt_number' => $rcpNumber,
                            'order_id'       => $orderId,
                            'status'         => $invStatus
                        ]
                    );

                    $db->commit();
                }
            }

            // Retrieve invoice data
            $stmtInv = $db->prepare("
                SELECT 
                    i.*,
                    o.order_number,
                    o.customer_name,
                    o.customer_phone,
                    NULL as customer_email,
                    o.delivery_address,
                    o.subtotal,
                    o.discount_amount,
                    o.delivery_fee,
                    o.total_amount,
                    o.payment_method,
                    o.order_status,
                    o.delivery_notes as order_notes,
                    o.created_at as order_created_at,
                    cp.status as cod_status,
                    cp.amount_collected as collected_amount,
                    cp.remitted_at,
                    cp.notes as cod_notes
                FROM invoices i
                INNER JOIN orders o ON i.order_id = o.id
                LEFT JOIN cod_payments cp ON cp.order_id = o.id
                WHERE i.id = :id
            ");
            $stmtInv->execute([':id' => $invoiceId]);
            $invoice = $stmtInv->fetch();

            if (!$invoice) {
                send_json(false, "Invoice #{$invoiceId} not found.", null, [], 404);
            }

            // Auto-sync status if order changed after invoice creation
            if ($invoice['status'] !== 'VOID') {
                if ($invoice['order_status'] === 'CANCELLED') {
                    $invoice['status'] = 'VOID';
                    $invoice['void_reason'] = 'Associated order was cancelled';
                    $db->prepare("UPDATE invoices SET status = 'VOID', void_reason = :reason WHERE id = :id")->execute([
                        ':reason' => $invoice['void_reason'],
                        ':id'     => $invoiceId
                    ]);
                } elseif (($invoice['order_status'] === 'DELIVERED' || in_array($invoice['cod_status'], ['COLLECTED', 'REMITTED'], true)) && $invoice['status'] === 'ISSUED') {
                    $invoice['status'] = 'PAID';
                    $invoice['amount_paid'] = $invoice['total_amount'];
                    $db->prepare("UPDATE invoices SET status = 'PAID', amount_paid = :amt WHERE id = :id")->execute([
                        ':amt' => $invoice['amount_paid'],
                        ':id'  => $invoiceId
                    ]);
                }
            }

            // Retrieve order items
            $stmtItems = $db->prepare("
                SELECT 
                    oi.id,
                    oi.product_id,
                    oi.quantity,
                    oi.unit_price,
                    oi.line_total as subtotal,
                    oi.product_name,
                    p.sku
                FROM order_items oi
                LEFT JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = :order_id
                ORDER BY oi.id ASC
            ");
            $stmtItems->execute([':order_id' => $invoice['order_id']]);
            $items = $stmtItems->fetchAll();

            $confirmedByName = get_order_confirmed_by_name($db, (int) $invoice['order_id']);

            $businessProfile = [
                'name'         => APP_NAME,
                'tagline'      => 'Prestige Apple Panel • Official Operations & Billing',
                'address'      => '123 Commerce Avenue, Business District, Metro Manila',
                'phone'        => '+63 (02) 8123-4567',
                'email'        => 'billing@prestigeapple.ph',
                'currency'     => APP_CURRENCY,
                'tax_rate'     => '0.00%',
                'tax_type'     => 'Zero Tax / Tax-Exempt',
                'tax_note'     => 'All transactions are strictly zero-taxed. Amount Due = Subtotal - Discount + Delivery Fee.'
            ];

            send_json(true, 'Invoice details retrieved successfully.', [
                'invoice'  => [
                    'id'                => (int) $invoice['id'],
                    'order_id'          => (int) $invoice['order_id'],
                    'invoice_number'    => $invoice['invoice_number'],
                    'receipt_number'    => $invoice['receipt_number'],
                    'issued_date'       => $invoice['issued_date'],
                    'amount_paid'       => (float) $invoice['amount_paid'],
                    'status'            => $invoice['status'],
                    'void_reason'       => $invoice['void_reason'],
                    'authorized_signer' => $confirmedByName,
                    'created_at'        => $invoice['created_at'],
                    'updated_at'        => $invoice['updated_at'] ?? $invoice['created_at']
                ],
                'order'    => [
                    'order_number'      => $invoice['order_number'],
                    'customer_name'     => $invoice['customer_name'],
                    'customer_phone'    => $invoice['customer_phone'],
                    'customer_email'    => $invoice['customer_email'],
                    'delivery_address'  => $invoice['delivery_address'],
                    'subtotal'          => (float) $invoice['subtotal'],
                    'discount_amount'   => (float) $invoice['discount_amount'],
                    'delivery_fee'      => (float) $invoice['delivery_fee'],
                    'tax_amount'        => 0.00,
                    'total_amount'      => (float) $invoice['total_amount'],
                    'payment_method'    => $invoice['payment_method'],
                    'order_status'      => $invoice['order_status'],
                    'confirmed_by_name' => $confirmedByName,
                    'notes'             => $invoice['order_notes'],
                    'created_at'        => $invoice['order_created_at']
                ],
                'cod'      => [
                    'status'           => $invoice['cod_status'] ?? 'PENDING',
                    'collected_amount' => $invoice['collected_amount'] !== null ? (float) $invoice['collected_amount'] : null,
                    'remitted_at'      => $invoice['remitted_at'],
                    'notes'            => $invoice['cod_notes']
                ],
                'items'    => $items,
                'business' => $businessProfile
            ]);
            break;

        // ======================================================================
        // ACTION: void_invoice (POST, Admin Only)
        // ======================================================================
        case 'void_invoice':
            if (!has_permission('invoices_manage')) {
                send_json(false, 'Forbidden: Administrator privileges required to void invoices.', null, [], 403);
            }

            require_csrf($input);

            $invoiceId = (int) ($input['invoice_id'] ?? 0);
            $reason = trim((string) ($input['reason'] ?? ''));

            if ($invoiceId <= 0) {
                send_json(false, 'Valid Invoice ID is required.', null, ['invoice_id' => 'Invoice ID is required.'], 422);
            }

            if ($reason === '' || mb_strlen($reason) < 5) {
                send_json(false, 'A justification reason of at least 5 characters is required.', null, [
                    'reason' => 'Reason must be at least 5 characters.'
                ], 422);
            }

            $stmtFind = $db->prepare("SELECT * FROM invoices WHERE id = :id");
            $stmtFind->execute([':id' => $invoiceId]);
            $existing = $stmtFind->fetch();

            if (!$existing) {
                send_json(false, "Invoice #{$invoiceId} not found.", null, [], 404);
            }

            if ($existing['status'] === 'VOID') {
                send_json(false, "Invoice #{$existing['invoice_number']} is already VOID.", null, [], 422);
            }

            $stmtUpdate = $db->prepare("
                UPDATE invoices 
                SET status = 'VOID', void_reason = :reason, updated_at = NOW() 
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':reason' => $reason,
                ':id'     => $invoiceId
            ]);

            log_audit(
                'invoices',
                'VOID',
                $invoiceId,
                ['status' => $existing['status'], 'void_reason' => $existing['void_reason']],
                ['status' => 'VOID', 'void_reason' => $reason]
            );

            send_json(true, "Invoice #{$existing['invoice_number']} marked as VOID successfully.", [
                'invoice_id'     => $invoiceId,
                'invoice_number' => $existing['invoice_number'],
                'status'         => 'VOID',
                'void_reason'    => $reason
            ]);
            break;

        default:
            send_json(false, "Unknown or unsupported action '{$action}'.", null, [], 400);
            break;
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[Invoices Handler PDO Error] ' . $e->getMessage());
    send_json(false, 'A database error occurred while processing the request.', null, [
        'error' => 'Database operation failed.'
    ], 500);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[Invoices Handler Error] ' . $e->getMessage());
    send_json(false, 'An unexpected server error occurred.', null, [
        'error' => 'Internal server error.'
    ], 500);
}

