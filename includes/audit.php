<?php
/**
 * System Audit Logging Utility
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Record a critical action or mutation to the immutable audit_logs table
 */
function audit_log(
    string $module,
    string $action,
    ?int $recordId = null,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    try {
        $db = get_db();

        $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Unknown', 0, 255);

        $oldJson = $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
        $newJson = $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $db->prepare("
            INSERT INTO audit_logs (
                user_id, module, action, record_id, 
                old_values, new_values, ip_address, user_agent, created_at
            ) VALUES (
                :user_id, :module, :action, :record_id, 
                :old_values, :new_values, :ip_address, :user_agent, NOW()
            )
        ");

        $stmt->execute([
            ':user_id'    => $userId,
            ':module'     => $module,
            ':action'     => $action,
            ':record_id'  => $recordId,
            ':old_values' => $oldJson,
            ':new_values' => $newJson,
            ':ip_address' => $ip,
            ':user_agent' => $userAgent
        ]);
    } catch (Throwable $e) {
        // Non-blocking: An audit logging glitch must not crash primary user actions
        error_log('[Audit Log Error] ' . $e->getMessage());
    }
}

/**
 * Functional alias for audit_log
 */
if (!function_exists('log_audit')) {
    function log_audit(
        string $module,
        string $action,
        ?int $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        audit_log($module, $action, $recordId, $oldValues, $newValues);
    }
}


