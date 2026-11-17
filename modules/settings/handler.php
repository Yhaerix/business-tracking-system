<?php
/**
 * Settings, Audit Logs, and Operational Reminders Backend Handler
 * Small Business Sales and Expense Tracking System
 *
 * Supported Actions:
 * - get_settings       (GET/POST, Permission: settings_view)
 * - update_settings    (POST,     Permission: settings_manage, CSRF Protected)
 * - list_audit_logs    (GET/POST, Permission: settings_view / audit_view)
 * - list_reminders     (GET/POST, Permission: settings_view)
 * - create_reminder    (POST,     Permission: settings_manage, CSRF Protected)
 * - toggle_reminder    (POST,     Permission: settings_manage, CSRF Protected)
 * - delete_reminder    (POST,     Permission: settings_manage, CSRF Protected)
 * - execute_reminder   (POST,     Permission: settings_manage, CSRF Protected)
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
 * Standardized JSON envelope
 */
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

/**
 * Extract request payload supporting JSON bodies, POST, and GET
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
 * Enforce Anti-CSRF Token check for state mutations
 */
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
        // 1. STORE SETTINGS
        // =====================================================================

        case 'get_settings':
            if (!has_permission('settings_view')) {
                send_json(false, 'Access denied: Insufficient permissions (settings_view required)', null, [], 403);
            }

            $stmt = $db->query("SELECT setting_key, setting_value, updated_at FROM store_settings");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $defaults = [
                'store_name'              => 'Prestige Apple Store',
                'contact_email'           => 'support@prestigeapple.ph',
                'contact_phone'           => '+63 917 800 2775',
                'currency_symbol'         => '₱',
                'default_low_stock_alert' => '5',
                'coins_name'              => 'Prestige Coins',
                'coins_symbol'            => '🪙',
                'coins_conversion_rate'   => '1.00',
                'coins_enabled'           => '1'
            ];

            $settings = $defaults;
            $lastUpdated = null;

            foreach ($rows as $row) {
                $key = $row['setting_key'];
                $val = $row['setting_value'];
                $settings[$key] = $val;
                if ($lastUpdated === null || ($row['updated_at'] && $row['updated_at'] > $lastUpdated)) {
                    $lastUpdated = $row['updated_at'];
                }
            }

            send_json(true, 'Store settings retrieved successfully.', [
                'settings'     => $settings,
                'last_updated' => $lastUpdated
            ]);
            break;

        case 'update_settings':
            if (!has_permission('settings_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (settings_manage required)', null, [], 403);
            }

            require_csrf($input);

            $storeName = trim((string) ($input['store_name'] ?? ''));
            $contactEmail = trim((string) ($input['contact_email'] ?? ''));
            $contactPhone = trim((string) ($input['contact_phone'] ?? ''));
            $currencySymbol = trim((string) ($input['currency_symbol'] ?? ''));
            $lowStockAlertRaw = $input['default_low_stock_alert'] ?? '5';

            $errors = [];

            if ($storeName === '' || mb_strlen($storeName) < 2 || mb_strlen($storeName) > 100) {
                $errors['store_name'] = 'Store name is required and must be between 2 and 100 characters.';
            }

            if ($contactEmail === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                $errors['contact_email'] = 'A valid contact email address is required.';
            }

            if ($contactPhone === '' || mb_strlen($contactPhone) < 5 || mb_strlen($contactPhone) > 30) {
                $errors['contact_phone'] = 'A valid contact phone number is required (5-30 characters).';
            }

            if ($currencySymbol === '' || mb_strlen($currencySymbol) > 5) {
                $errors['currency_symbol'] = 'Currency symbol is required (1-5 characters).';
            }

            if (!is_numeric($lowStockAlertRaw) || (int) $lowStockAlertRaw < 0 || (int) $lowStockAlertRaw > 10000) {
                $errors['default_low_stock_alert'] = 'Default low stock alert threshold must be an integer between 0 and 10,000.';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed. Please verify the submitted settings.', null, $errors, 422);
            }

            $lowStockAlert = (string) (int) $lowStockAlertRaw;
            $coinsName = trim((string) ($input['coins_name'] ?? 'Prestige Coins'));
            $coinsSymbol = trim((string) ($input['coins_symbol'] ?? '🪙'));
            $coinsRate = trim((string) ($input['coins_conversion_rate'] ?? '1.00'));
            $coinsEnabled = isset($input['coins_enabled']) && ($input['coins_enabled'] === '1' || $input['coins_enabled'] === 1 || $input['coins_enabled'] === true) ? '1' : '0';

            $newSettings = [
                'store_name'              => $storeName,
                'contact_email'           => $contactEmail,
                'contact_phone'           => $contactPhone,
                'currency_symbol'         => $currencySymbol,
                'default_low_stock_alert' => $lowStockAlert,
                'coins_name'              => $coinsName !== '' ? $coinsName : 'Prestige Coins',
                'coins_symbol'            => $coinsSymbol !== '' ? $coinsSymbol : '🪙',
                'coins_conversion_rate'   => is_numeric($coinsRate) ? $coinsRate : '1.00',
                'coins_enabled'           => $coinsEnabled
            ];

            // Multi-step mutation inside transaction
            $db->beginTransaction();

            // Fetch old values for audit logging
            $stmtOld = $db->query("SELECT setting_key, setting_value FROM store_settings");
            $oldSettings = [];
            while ($row = $stmtOld->fetch(PDO::FETCH_ASSOC)) {
                $oldSettings[$row['setting_key']] = $row['setting_value'];
            }

            $stmtUpsert = $db->prepare("
                INSERT INTO store_settings (setting_key, setting_value, updated_at)
                VALUES (:key, :value, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");

            foreach ($newSettings as $key => $val) {
                $stmtUpsert->execute([
                    ':key'   => $key,
                    ':value' => $val
                ]);
            }

            // Audit Trail
            audit_log('settings', 'update_settings', null, $oldSettings, $newSettings);

            $db->commit();

            send_json(true, 'Store settings updated successfully.', [
                'settings' => $newSettings
            ]);
            break;

        // =====================================================================
        // 2. AUDIT LOGS
        // =====================================================================

        case 'list_audit_logs':
            if (!has_permission('settings_view') && !has_permission('audit_view')) {
                send_json(false, 'Access denied: Insufficient permissions (settings_view or audit_view required)', null, [], 403);
            }

            $module = trim((string) ($input['module'] ?? ''));
            $actionFilter = trim((string) ($input['action_filter'] ?? $input['action_type'] ?? ''));
            $userId = !empty($input['user_id']) ? (int) $input['user_id'] : null;
            $dateFrom = trim((string) ($input['date_from'] ?? ''));
            $dateTo = trim((string) ($input['date_to'] ?? ''));
            $search = trim((string) ($input['search'] ?? ''));
            $page = max(1, (int) ($input['page'] ?? 1));
            $limit = min(100, max(1, (int) ($input['limit'] ?? 15)));
            $offset = ($page - 1) * $limit;

            $where = ['1=1'];
            $params = [];

            if ($module !== '') {
                $where[] = 'a.module = :module';
                $params[':module'] = $module;
            }

            if ($actionFilter !== '') {
                $where[] = 'a.action = :action_filter';
                $params[':action_filter'] = $actionFilter;
            }

            if ($userId !== null && $userId > 0) {
                $where[] = 'a.user_id = :user_id';
                $params[':user_id'] = $userId;
            }

            if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
                $where[] = 'a.created_at >= :date_from';
                $params[':date_from'] = $dateFrom . ' 00:00:00';
            }

            if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                $where[] = 'a.created_at <= :date_to';
                $params[':date_to'] = $dateTo . ' 23:59:59';
            }

            if ($search !== '') {
                $where[] = '(a.module LIKE :s_mod OR a.action LIKE :s_act OR a.ip_address LIKE :s_ip OR u.full_name LIKE :s_name OR u.username LIKE :s_user)';
                $sTerm = '%' . $search . '%';
                $params[':s_mod'] = $sTerm;
                $params[':s_act'] = $sTerm;
                $params[':s_ip'] = $sTerm;
                $params[':s_name'] = $sTerm;
                $params[':s_user'] = $sTerm;
            }

            $whereSql = implode(' AND ', $where);

            // Total count
            $stmtCount = $db->prepare("
                SELECT COUNT(*) 
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE {$whereSql}
            ");
            $stmtCount->execute($params);
            $totalRecords = (int) $stmtCount->fetchColumn();
            $totalPages = (int) ceil($totalRecords / $limit);

            // Items
            $stmtLogs = $db->prepare("
                SELECT a.id, a.user_id, a.module, a.action, a.record_id,
                       a.old_values, a.new_values, a.ip_address, a.user_agent, a.created_at,
                       u.full_name AS user_full_name, u.username
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE {$whereSql}
                ORDER BY a.id DESC
                LIMIT :limit OFFSET :offset
            ");

            foreach ($params as $k => $v) {
                $stmtLogs->bindValue($k, $v);
            }
            $stmtLogs->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmtLogs->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmtLogs->execute();

            $rawLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
            $logs = [];

            foreach ($rawLogs as $row) {
                $oldVals = null;
                if (!empty($row['old_values'])) {
                    $decoded = json_decode((string) $row['old_values'], true);
                    $oldVals = is_array($decoded) ? $decoded : $row['old_values'];
                }

                $newVals = null;
                if (!empty($row['new_values'])) {
                    $decoded = json_decode((string) $row['new_values'], true);
                    $newVals = is_array($decoded) ? $decoded : $row['new_values'];
                }

                $logs[] = [
                    'id'             => (int) $row['id'],
                    'user_id'        => !empty($row['user_id']) ? (int) $row['user_id'] : null,
                    'user_full_name' => $row['user_full_name'] ?? 'System',
                    'username'       => $row['username'] ?? 'system',
                    'module'         => $row['module'],
                    'action'         => $row['action'],
                    'record_id'      => !empty($row['record_id']) ? (int) $row['record_id'] : null,
                    'old_values'     => $oldVals,
                    'new_values'     => $newVals,
                    'ip_address'     => $row['ip_address'],
                    'user_agent'     => $row['user_agent'],
                    'created_at'     => $row['created_at']
                ];
            }

            send_json(true, 'Audit logs retrieved successfully.', [
                'items'      => $logs,
                'pagination' => [
                    'current_page'  => $page,
                    'per_page'      => $limit,
                    'total_records' => $totalRecords,
                    'total_pages'   => $totalPages
                ]
            ]);
            break;

        // =====================================================================
        // 3. OPERATIONAL REMINDERS
        // =====================================================================

        case 'list_reminders':
            if (!has_permission('settings_view')) {
                send_json(false, 'Access denied: Insufficient permissions (settings_view required)', null, [], 403);
            }

            // Auto-seed sample incoming delivery reminders if the reminders table is completely empty
            $totalExisting = (int) $db->query("SELECT COUNT(*) FROM reminders")->fetchColumn();
            if ($totalExisting === 0) {
                $seedUid = (int) ($currentUser['id'] ?? 1);
                $stmtSeed = $db->prepare("
                    INSERT INTO reminders (user_id, title, type, target_date, notes, is_completed, is_active, created_at)
                    VALUES (:uid, :title, :type, :target_date, :notes, 0, 1, NOW())
                ");
                $stmtSeed->execute([
                    ':uid'         => $seedUid,
                    ':title'       => 'Incoming Delivery: iPhone 16 Pro Max 256GB (15 units) — Supplier Shipment',
                    ':type'        => 'INCOMING_DELIVERY',
                    ':target_date' => date('Y-m-d 10:00:00', strtotime('+1 day')),
                    ':notes'       => 'Verify factory seals, IMEI serial numbers, and log restock in Stock Ledger upon arrival.'
                ]);
                $stmtSeed->execute([
                    ':uid'         => $seedUid,
                    ':title'       => 'Incoming Delivery: AirPods Pro 2 (USB-C) & 20W USB-C Power Adapters (30 units)',
                    ':type'        => 'INCOMING_DELIVERY',
                    ':target_date' => date('Y-m-d 14:00:00', strtotime('+2 days')),
                    ':notes'       => 'Receive from Apple Authorized Distributor courier & inspect outer box condition.'
                ]);
                $stmtSeed->execute([
                    ':uid'         => $seedUid,
                    ':title'       => 'Customer Deliveries: Follow up In-Transit & Ready-to-Ship COD Orders',
                    ':type'        => 'ORDER_DISPATCH',
                    ':target_date' => date('Y-m-d 16:00:00'),
                    ':notes'       => 'Check courier waybill milestones and confirm scheduled customer deliveries for today.'
                ]);
            }

            $typeFilter = trim((string) ($input['type'] ?? ''));
            $statusFilter = trim((string) ($input['status'] ?? ''));
            $search = trim((string) ($input['search'] ?? ''));

            $where = ['1=1'];
            $params = [];

            if ($typeFilter !== '' && $typeFilter !== 'all') {
                $where[] = 'r.type = :type';
                $params[':type'] = $typeFilter;
            }

            if ($statusFilter === 'active') {
                $where[] = 'r.is_active = 1 AND r.is_completed = 0';
            } elseif ($statusFilter === 'completed') {
                $where[] = 'r.is_completed = 1';
            } elseif ($statusFilter === 'inactive') {
                $where[] = 'r.is_active = 0';
            }

            if ($search !== '') {
                $where[] = '(r.title LIKE :s_title OR r.notes LIKE :s_notes)';
                $params[':s_title'] = '%' . $search . '%';
                $params[':s_notes'] = '%' . $search . '%';
            }

            $whereSql = implode(' AND ', $where);

            $stmt = $db->prepare("
                SELECT r.id, r.user_id, r.title, r.type, r.target_date, r.notes,
                       r.is_completed, r.is_active, r.last_run_at, r.created_at, r.updated_at,
                       u.full_name AS creator_name
                FROM reminders r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE {$whereSql}
                ORDER BY r.is_completed ASC, r.target_date ASC, r.id DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $nowTimestamp = time();
            $items = [];
            $summaryCounts = ['total' => 0, 'active' => 0, 'due' => 0, 'completed' => 0];

            foreach ($rows as $row) {
                $targetTs = strtotime((string) $row['target_date']);
                $isDue = ($targetTs !== false && $targetTs <= $nowTimestamp && (int) $row['is_completed'] === 0 && (int) $row['is_active'] === 1);

                $summaryCounts['total']++;
                if ((int) $row['is_completed'] === 1) {
                    $summaryCounts['completed']++;
                } elseif ((int) $row['is_active'] === 1) {
                    $summaryCounts['active']++;
                    if ($isDue) {
                        $summaryCounts['due']++;
                    }
                }

                $items[] = [
                    'id'           => (int) $row['id'],
                    'user_id'      => (int) $row['user_id'],
                    'creator_name' => $row['creator_name'] ?? 'System',
                    'title'        => $row['title'],
                    'type'         => $row['type'],
                    'target_date'  => $row['target_date'],
                    'notes'        => $row['notes'],
                    'is_completed' => (bool) $row['is_completed'],
                    'is_active'    => (bool) $row['is_active'],
                    'is_due'       => $isDue,
                    'last_run_at'  => $row['last_run_at'],
                    'created_at'   => $row['created_at'],
                    'updated_at'   => $row['updated_at']
                ];
            }

            send_json(true, 'Reminders retrieved successfully.', [
                'items'   => $items,
                'summary' => $summaryCounts
            ]);
            break;

        case 'create_reminder':
            if (!has_permission('settings_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (settings_manage required)', null, [], 403);
            }

            require_csrf($input);

            $title = trim((string) ($input['title'] ?? ''));
            $type = trim((string) ($input['type'] ?? ''));
            $targetDateRaw = trim((string) ($input['target_date'] ?? ''));
            $notes = trim((string) ($input['notes'] ?? ''));

            $allowedTypes = ['INCOMING_DELIVERY', 'ORDER_DISPATCH', 'LOW_STOCK_CHECK', 'COD_FOLLOWUP', 'EXPENSE_DUE', 'CUSTOM'];
            $errors = [];

            if ($title === '' || mb_strlen($title) < 3 || mb_strlen($title) > 150) {
                $errors['title'] = 'Reminder title is required and must be between 3 and 150 characters.';
            }

            if (!in_array($type, $allowedTypes, true)) {
                $errors['type'] = 'Invalid reminder type selected. Allowed: ' . implode(', ', $allowedTypes);
            }

            if ($targetDateRaw === '') {
                $errors['target_date'] = 'Target date and time is required.';
            } else {
                $normalizedDate = str_replace('T', ' ', $targetDateRaw);
                $ts = strtotime($normalizedDate);
                if ($ts === false) {
                    $errors['target_date'] = 'Target date must be a valid date/time format (YYYY-MM-DD HH:MM).';
                } else {
                    $targetDate = date('Y-m-d H:i:s', $ts);
                }
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed. Please verify submitted reminder fields.', null, $errors, 422);
            }

            $userId = (int) ($currentUser['id'] ?? 1);

            $stmt = $db->prepare("
                INSERT INTO reminders (user_id, title, type, target_date, notes, is_completed, is_active, created_at)
                VALUES (:user_id, :title, :type, :target_date, :notes, 0, 1, NOW())
            ");
            $stmt->execute([
                ':user_id'     => $userId,
                ':title'       => $title,
                ':type'        => $type,
                ':target_date' => $targetDate,
                ':notes'       => $notes !== '' ? $notes : null
            ]);

            $reminderId = (int) $db->lastInsertId();

            audit_log('reminders', 'create', $reminderId, null, [
                'title'       => $title,
                'type'        => $type,
                'target_date' => $targetDate,
                'user_id'     => $userId
            ]);

            send_json(true, 'Operational reminder scheduled successfully.', [
                'reminder_id' => $reminderId
            ], [], 201);
            break;

        case 'toggle_reminder':
            if (!has_permission('settings_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (settings_manage required)', null, [], 403);
            }

            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid reminder ID is required.', null, ['id' => 'Invalid ID'], 422);
            }

            $stmtCheck = $db->prepare("SELECT * FROM reminders WHERE id = :id LIMIT 1");
            $stmtCheck->execute([':id' => $id]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                send_json(false, 'Reminder not found.', null, [], 404);
            }

            $field = trim((string) ($input['field'] ?? ''));
            $newCompleted = (int) $existing['is_completed'];
            $newActive = (int) $existing['is_active'];

            if ($field === 'is_completed' || isset($input['is_completed'])) {
                if (isset($input['is_completed'])) {
                    $newCompleted = !empty($input['is_completed']) ? 1 : 0;
                } else {
                    $newCompleted = $newCompleted === 1 ? 0 : 1;
                }
            } elseif ($field === 'is_active' || isset($input['is_active'])) {
                if (isset($input['is_active'])) {
                    $newActive = !empty($input['is_active']) ? 1 : 0;
                } else {
                    $newActive = $newActive === 1 ? 0 : 1;
                }
            } else {
                // Default toggle active state
                $newActive = $newActive === 1 ? 0 : 1;
            }

            $stmtUpdate = $db->prepare("
                UPDATE reminders 
                SET is_completed = :is_completed, is_active = :is_active, updated_at = NOW() 
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':is_completed' => $newCompleted,
                ':is_active'    => $newActive,
                ':id'           => $id
            ]);

            audit_log('reminders', 'toggle', $id, [
                'is_completed' => (int) $existing['is_completed'],
                'is_active'    => (int) $existing['is_active']
            ], [
                'is_completed' => $newCompleted,
                'is_active'    => $newActive
            ]);

            send_json(true, 'Reminder status updated successfully.', [
                'id'           => $id,
                'is_completed' => (bool) $newCompleted,
                'is_active'    => (bool) $newActive
            ]);
            break;

        case 'delete_reminder':
            if (!has_permission('settings_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (settings_manage required)', null, [], 403);
            }

            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid reminder ID is required.', null, ['id' => 'Invalid ID'], 422);
            }

            $stmtCheck = $db->prepare("SELECT * FROM reminders WHERE id = :id LIMIT 1");
            $stmtCheck->execute([':id' => $id]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                send_json(false, 'Reminder not found or already deleted.', null, [], 404);
            }

            $stmtDel = $db->prepare("DELETE FROM reminders WHERE id = :id");
            $stmtDel->execute([':id' => $id]);

            audit_log('reminders', 'delete', $id, $existing, null);

            send_json(true, 'Reminder removed successfully.', ['id' => $id]);
            break;

        case 'execute_reminder':
            if (!has_permission('settings_manage')) {
                send_json(false, 'Access denied: Insufficient permissions (settings_manage required)', null, [], 403);
            }

            require_csrf($input);

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid reminder ID is required.', null, ['id' => 'Invalid ID'], 422);
            }

            $stmtCheck = $db->prepare("SELECT * FROM reminders WHERE id = :id LIMIT 1");
            $stmtCheck->execute([':id' => $id]);
            $reminder = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$reminder) {
                send_json(false, 'Reminder not found.', null, [], 404);
            }

            $findingsCount = 0;
            $summary = '';
            $currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';

            // Execution logic per reminder type
            switch ($reminder['type']) {
                case 'INCOMING_DELIVERY':
                    $findingsCount = 1;
                    $targetFormatted = date('M j, Y g:i A', strtotime($reminder['target_date']));
                    $summary = "Incoming delivery item reminder checked: \"{$reminder['title']}\". Expected arrival: {$targetFormatted}." . (!empty($reminder['notes']) ? " Notes: {$reminder['notes']}" : "");
                    break;

                case 'ORDER_DISPATCH':
                    $stmtOrd = $db->query("
                        SELECT COUNT(*) 
                        FROM orders 
                        WHERE order_status IN ('CONFIRMED', 'DISPATCHED') 
                          AND deleted_at IS NULL
                    ");
                    $findingsCount = (int) $stmtOrd->fetchColumn();
                    $summary = "Upcoming customer delivery & dispatch scan executed. {$findingsCount} active order(s) are currently confirmed or in transit for customer delivery.";
                    break;

                case 'LOW_STOCK_CHECK':
                    $stmtLow = $db->query("
                        SELECT COUNT(*) 
                        FROM products 
                        WHERE current_stock <= low_stock_threshold 
                          AND deleted_at IS NULL 
                          AND status = 'active'
                    ");
                    $findingsCount = (int) $stmtLow->fetchColumn();
                    $summary = "Low stock scan executed. {$findingsCount} product(s) are currently at or below their restock threshold.";
                    break;

                case 'COD_FOLLOWUP':
                    $stmtCod = $db->query("
                        SELECT COUNT(*) 
                        FROM orders 
                        WHERE payment_method = 'COD' 
                          AND payment_status = 'PENDING_COLLECTION' 
                          AND order_status IN ('DISPATCHED', 'DELIVERED') 
                          AND deleted_at IS NULL
                    ");
                    $findingsCount = (int) $stmtCod->fetchColumn();
                    $summary = "COD cash collection audit executed. {$findingsCount} dispatched or delivered order(s) are awaiting payment collection/drawer remittance.";
                    break;

                case 'EXPENSE_DUE':
                    $stmtExp = $db->query("
                        SELECT COUNT(*), COALESCE(SUM(amount), 0.00) 
                        FROM expenses 
                        WHERE deleted_at IS NULL 
                          AND expense_date >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
                    ");
                    $expRow = $stmtExp->fetch(PDO::FETCH_NUM);
                    $findingsCount = (int) ($expRow[0] ?? 0);
                    $totalAmt = (float) ($expRow[1] ?? 0.00);
                    $summary = "Expense operational cycle executed. {$findingsCount} expense(s) recorded in the past 7 days totaling {$currency}" . number_format($totalAmt, 2) . ".";
                    break;

                case 'CUSTOM':
                default:
                    $findingsCount = 1;
                    $summary = "Custom operational reminder checked: \"{$reminder['title']}\". Scheduled target date: " . date('M j, Y g:i A', strtotime($reminder['target_date'])) . ".";
                    break;
            }

            // Wrap state changes and notification insertion in atomic transaction
            $db->beginTransaction();

            // Insert system notification
            $stmtNotif = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, reference_type, reference_id, is_read, created_at)
                VALUES (:user_id, 'SYSTEM', :title, :message, 'reminders', :reference_id, 0, NOW())
            ");
            $stmtNotif->execute([
                ':user_id'      => (int) ($currentUser['id'] ?? $reminder['user_id']),
                ':title'        => 'Reminder Check: ' . $reminder['title'],
                ':message'      => $summary,
                ':reference_id' => $id
            ]);

            // Update reminder last_run_at timestamp
            $stmtUpdateRun = $db->prepare("UPDATE reminders SET last_run_at = NOW(), updated_at = NOW() WHERE id = :id");
            $stmtUpdateRun->execute([':id' => $id]);

            $executedAt = date('Y-m-d H:i:s');

            // Audit Trail
            audit_log('reminders', 'execute_reminder', $id, null, [
                'type'           => $reminder['type'],
                'findings_count' => $findingsCount,
                'summary'        => $summary,
                'executed_at'    => $executedAt
            ]);

            $db->commit();

            send_json(true, 'Reminder executed successfully.', [
                'executed_at'    => $executedAt,
                'findings_count' => $findingsCount,
                'summary'        => $summary
            ]);
            break;

        default:
            send_json(false, 'Unknown or invalid action requested.', null, ['action' => 'Invalid action parameter'], 400);
            break;
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[Settings Handler Database Error] ' . $e->getMessage());
    send_json(false, 'A database error occurred while processing your request. Please try again later.', null, [], 500);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[Settings Handler System Error] ' . $e->getMessage());
    send_json(false, 'An unexpected server error occurred. Please contact technical support.', null, [], 500);
}
