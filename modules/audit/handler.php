<?php
/**
 * Audit Trail & Activity Logging Backend Handler
 * Small Business Sales and Expense Tracking System
 *
 * Actions:
 * - list_audit_logs    (GET/POST, Permission: audit_view)
 * - get_audit_detail   (GET/POST, Permission: audit_view)
 * - get_filter_options (GET/POST, Permission: audit_view)
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

// 1. Session Authentication & Audit Permission Guard (Admin & Manager)
require_login();
require_permission('audit_view');

$input = get_request_data();
$action = trim((string) ($input['action'] ?? ''));
$db = get_db();

try {
    switch ($action) {
        // =====================================================================
        // 1. LIST PAGINATED AUDIT LOGS
        // =====================================================================
        case 'list_audit_logs':
            $page = max(1, (int) ($input['page'] ?? 1));
            $limit = min(100, max(1, (int) ($input['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $module = trim((string) ($input['module'] ?? ''));
            $actionName = trim((string) ($input['action_name'] ?? $input['filter_action'] ?? ''));
            $userId = !empty($input['user_id']) ? (int) $input['user_id'] : null;
            $startDate = trim((string) ($input['date_from'] ?? $input['start_date'] ?? ''));
            $endDate = trim((string) ($input['date_to'] ?? $input['end_date'] ?? ''));
            $search = trim((string) ($input['search'] ?? ''));

            $where = ['1=1'];
            $params = [];

            if ($module !== '') {
                $where[] = 'a.module = :module';
                $params[':module'] = $module;
            }

            if ($actionName !== '') {
                $where[] = 'a.action = :action_name';
                $params[':action_name'] = $actionName;
            }

            if ($userId !== null && $userId > 0) {
                $where[] = 'a.user_id = :user_id';
                $params[':user_id'] = $userId;
            }

            if ($startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                $where[] = 'a.created_at >= :start_date';
                $params[':start_date'] = $startDate . ' 00:00:00';
            }

            if ($endDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                $where[] = 'a.created_at <= :end_date';
                $params[':end_date'] = $endDate . ' 23:59:59';
            }

            if ($search !== '') {
                $where[] = '(a.module LIKE :s_mod OR a.action LIKE :s_act OR a.ip_address LIKE :s_ip OR u.username LIKE :s_user OR u.full_name LIKE :s_name OR CAST(a.record_id AS CHAR) LIKE :s_rec)';
                $sTerm = '%' . $search . '%';
                $params[':s_mod'] = $sTerm;
                $params[':s_act'] = $sTerm;
                $params[':s_ip'] = $sTerm;
                $params[':s_user'] = $sTerm;
                $params[':s_name'] = $sTerm;
                $params[':s_rec'] = $sTerm;
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

            // Paginated log rows
            $stmtLogs = $db->prepare("
                SELECT a.id, a.user_id, a.module, a.action, a.record_id,
                       a.old_values, a.new_values, a.ip_address, a.user_agent, a.created_at,
                       u.username, u.full_name,
                       r.label AS role_label
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN roles r ON u.role_id = r.id
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

            $rawLogs = $stmtLogs->fetchAll();
            $logs = [];
            foreach ($rawLogs as $row) {
                $oldVals = null;
                if (!empty($row['old_values'])) {
                    $oldVals = is_string($row['old_values']) ? json_decode($row['old_values'], true) : $row['old_values'];
                }

                $newVals = null;
                if (!empty($row['new_values'])) {
                    $newVals = is_string($row['new_values']) ? json_decode($row['new_values'], true) : $row['new_values'];
                }

                $logs[] = [
                    'id'          => (int) $row['id'],
                    'user_id'     => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                    'username'    => $row['username'] ?? 'System',
                    'full_name'   => $row['full_name'] ?? 'System Process',
                    'role_label'  => $row['role_label'] ?? 'System',
                    'module'      => $row['module'],
                    'action'      => $row['action'],
                    'record_id'   => $row['record_id'] !== null ? (int) $row['record_id'] : null,
                    'old_values'  => $oldVals,
                    'new_values'  => $newVals,
                    'ip_address'  => $row['ip_address'],
                    'user_agent'  => $row['user_agent'],
                    'created_at'  => $row['created_at']
                ];
            }

            $totalPages = $totalRecords > 0 ? (int) ceil($totalRecords / $limit) : 1;

            send_json(true, 'Audit logs retrieved successfully', [
                'logs'       => $logs,
                'pagination' => [
                    'total_records' => $totalRecords,
                    'current_page'  => $page,
                    'page'          => $page,
                    'limit'         => $limit,
                    'per_page'      => $limit,
                    'total_pages'   => $totalPages
                ]
            ]);
            break;

        // =====================================================================
        // 2. GET AUDIT DETAIL
        // =====================================================================
        case 'get_audit_detail':
            $logId = (int) ($input['log_id'] ?? $input['id'] ?? 0);
            if ($logId <= 0) {
                send_json(false, 'Valid Audit Log ID is required.', null, ['log_id' => 'Invalid ID'], 422);
            }

            $stmt = $db->prepare("
                SELECT a.id, a.user_id, a.module, a.action, a.record_id,
                       a.old_values, a.new_values, a.ip_address, a.user_agent, a.created_at,
                       u.username, u.full_name,
                       r.name AS role_name, r.label AS role_label
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE a.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $logId]);
            $row = $stmt->fetch();

            if (!$row) {
                send_json(false, 'Audit log entry not found.', null, [], 404);
            }

            $oldVals = null;
            if (!empty($row['old_values'])) {
                $oldVals = is_string($row['old_values']) ? json_decode($row['old_values'], true) : $row['old_values'];
            }

            $newVals = null;
            if (!empty($row['new_values'])) {
                $newVals = is_string($row['new_values']) ? json_decode($row['new_values'], true) : $row['new_values'];
            }

            $detail = [
                'id'          => (int) $row['id'],
                'user_id'     => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'username'    => $row['username'] ?? 'System',
                'full_name'   => $row['full_name'] ?? 'System Process',
                'role_name'   => $row['role_name'] ?? 'system',
                'role_label'  => $row['role_label'] ?? 'System',
                'module'      => $row['module'],
                'action'      => $row['action'],
                'record_id'   => $row['record_id'] !== null ? (int) $row['record_id'] : null,
                'old_values'  => $oldVals,
                'new_values'  => $newVals,
                'ip_address'  => $row['ip_address'],
                'user_agent'  => $row['user_agent'],
                'created_at'  => $row['created_at']
            ];

            send_json(true, 'Audit log detail retrieved successfully', [
                'log' => $detail
            ]);
            break;

        // =====================================================================
        // 3. GET FILTER OPTIONS
        // =====================================================================
        case 'get_filter_options':
            // Distinct modules
            $stmtModules = $db->query("
                SELECT DISTINCT module 
                FROM audit_logs 
                WHERE module IS NOT NULL AND module != '' 
                ORDER BY module ASC
            ");
            $modules = $stmtModules->fetchAll(PDO::FETCH_COLUMN);

            // Distinct actions
            $stmtActions = $db->query("
                SELECT DISTINCT action 
                FROM audit_logs 
                WHERE action IS NOT NULL AND action != '' 
                ORDER BY action ASC
            ");
            $actions = $stmtActions->fetchAll(PDO::FETCH_COLUMN);

            // Users list for dropdown
            $stmtUsers = $db->query("
                SELECT id, username, full_name 
                FROM users 
                ORDER BY full_name ASC
            ");
            $rawUsers = $stmtUsers->fetchAll();
            $users = [];
            foreach ($rawUsers as $u) {
                $users[] = [
                    'id'        => (int) $u['id'],
                    'username'  => $u['username'],
                    'full_name' => $u['full_name']
                ];
            }

            send_json(true, 'Filter options retrieved successfully', [
                'modules' => $modules,
                'actions' => $actions,
                'users'   => $users
            ]);
            break;

        default:
            send_json(false, 'Invalid or unrecognized action requested.', null, ['action' => $action], 400);
            break;
    }
} catch (PDOException $e) {
    error_log('[Audit Handler Database Error] ' . $e->getMessage());
    send_json(false, 'A database error occurred while querying audit logs.', null, [], 500);
} catch (Throwable $e) {
    error_log('[Audit Handler System Error] ' . $e->getMessage());
    send_json(false, 'An unexpected server error occurred.', null, [], 500);
}

