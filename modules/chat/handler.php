<?php
/**
 * Internal Team Communication & Chat API Handler
 * Small Business Sales and Expense Tracking System
 *
 * Supported Actions:
 *  - GET  ?action=conversations  : Get list of active staff + #team channel with unread counts & last message
 *  - GET  ?action=messages       : Get message thread for a channel/user with attached order snapshot
 *  - GET  ?action=unread_count   : Get total unread count for topbar badges
 *  - POST ?action=send           : Send a new message (text + optional order attachment)
 *  - POST ?action=mark_read      : Mark messages in a channel as read
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/rbac.php';

// Guard: Authentication required
require_login();
$user = current_user();
$userId = (int)$user['id'];
$db = get_db();

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

/**
 * Helper to emit structured JSON responses
 */
function send_json(bool $success, string $message, mixed $data = null, array $errors = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
        'errors'  => $errors
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    switch ($action) {
        // -------------------------------------------------------------
        // 1. GET TOTAL UNREAD COUNT
        // -------------------------------------------------------------
        case 'unread_count': {
            // Count unread direct messages addressed to current user
            $stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM chat_messages 
                WHERE recipient_id = :user_id 
                  AND is_read = 0
            ");
            $stmt->execute([':user_id' => $userId]);
            $unreadDirect = (int)$stmt->fetchColumn();

            send_json(true, 'Unread count fetched', ['unread' => $unreadDirect]);
            break;
        }

        // -------------------------------------------------------------
        // 2. GET CONVERSATIONS LIST
        // -------------------------------------------------------------
        case 'conversations': {
            $conversations = [];

            // 2A. Public Team Channel (#team)
            $teamStmt = $db->prepare("
                SELECT m.id, m.message, m.created_at, m.order_id, u.full_name AS sender_name, u.username AS sender_username
                FROM chat_messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.recipient_id IS NULL
                ORDER BY m.id DESC
                LIMIT 1
            ");
            $teamStmt->execute();
            $lastTeam = $teamStmt->fetch(PDO::FETCH_ASSOC);

            $conversations[] = [
                'key'         => 'team',
                'name'        => 'Team Channel',
                'role_label'  => 'Store-wide Broadcast',
                'is_team'     => true,
                'initials'    => '#',
                'other_id'    => null,
                'unread'      => 0,
                'last_message'=> $lastTeam ? $lastTeam['message'] : 'No messages yet',
                'last_sender' => $lastTeam ? $lastTeam['sender_name'] : '',
                'last_time'   => $lastTeam ? $lastTeam['created_at'] : null,
                'has_order'   => !empty($lastTeam['order_id'])
            ];

            // 2B. Direct Staff Conversations (Other active users)
            $usersStmt = $db->prepare("
                SELECT u.id, u.username, u.full_name, r.label AS role_label, r.name AS role_name
                FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE u.id != :user_id AND u.is_active = 1
                ORDER BY r.id ASC, u.full_name ASC
            ");
            $usersStmt->execute([':user_id' => $userId]);
            $staffList = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($staffList as $staff) {
                $otherId = (int)$staff['id'];

                // Get unread count from this staff member to current user
                $unreadStmt = $db->prepare("
                    SELECT COUNT(*) 
                    FROM chat_messages 
                    WHERE sender_id = :other_id 
                      AND recipient_id = :user_id 
                      AND is_read = 0
                ");
                $unreadStmt->execute([':other_id' => $otherId, ':user_id' => $userId]);
                $unread = (int)$unreadStmt->fetchColumn();

                // Get last direct message between user and staff
                $lastMsgStmt = $db->prepare("
                    SELECT m.id, m.sender_id, m.message, m.created_at, m.order_id
                    FROM chat_messages m
                    WHERE (m.sender_id = :uid1 AND m.recipient_id = :oid1)
                       OR (m.sender_id = :oid2 AND m.recipient_id = :uid2)
                    ORDER BY m.id DESC
                    LIMIT 1
                ");
                $lastMsgStmt->execute([
                    ':uid1' => $userId,
                    ':oid1' => $otherId,
                    ':oid2' => $otherId,
                    ':uid2' => $userId
                ]);
                $lastMsg = $lastMsgStmt->fetch(PDO::FETCH_ASSOC);

                // Initials for avatar
                $words = explode(' ', trim($staff['full_name']));
                $initials = '';
                if (count($words) >= 2) {
                    $initials = strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
                } else {
                    $initials = strtoupper(substr($staff['full_name'], 0, 2));
                }

                $conversations[] = [
                    'key'         => 'user_' . $otherId,
                    'name'        => $staff['full_name'],
                    'role_label'  => $staff['role_label'],
                    'role_name'   => $staff['role_name'],
                    'is_team'     => false,
                    'initials'    => $initials,
                    'other_id'    => $otherId,
                    'unread'      => $unread,
                    'last_message'=> $lastMsg ? $lastMsg['message'] : 'No messages yet',
                    'last_sender' => $lastMsg ? (($lastMsg['sender_id'] == $userId) ? 'You' : $staff['full_name']) : '',
                    'last_time'   => $lastMsg ? $lastMsg['created_at'] : null,
                    'has_order'   => !empty($lastMsg['order_id'])
                ];
            }

            send_json(true, 'Conversations loaded', [
                'current_user'  => $user,
                'conversations' => $conversations
            ]);
            break;
        }

        // -------------------------------------------------------------
        // 3. GET MESSAGES FOR A CHANNEL / USER
        // -------------------------------------------------------------
        case 'messages': {
            $channel = trim($_GET['channel'] ?? '');
            $afterId = (int)($_GET['after_id'] ?? 0);

            if (empty($channel)) {
                send_json(false, 'Channel parameter is required', null, ['channel' => 'Missing channel'], 400);
            }

            $messages = [];
            $channelInfo = [];

            if ($channel === 'team') {
                $channelInfo = [
                    'key'       => 'team',
                    'name'      => 'Team Channel',
                    'is_team'   => true,
                    'role_label'=> 'Store-wide Broadcast'
                ];

                $query = "
                    SELECT m.id, m.sender_id, m.recipient_id, m.message, m.order_id, 
                           m.is_read, m.read_at, m.created_at,
                           u.full_name AS sender_name, u.username AS sender_username,
                           r.label AS sender_role,
                           o.order_number, o.order_status, o.total_amount
                    FROM chat_messages m
                    JOIN users u ON m.sender_id = u.id
                    JOIN roles r ON u.role_id = r.id
                    LEFT JOIN orders o ON m.order_id = o.id
                    WHERE m.recipient_id IS NULL
                ";
                $params = [];
                if ($afterId > 0) {
                    $query .= " AND m.id > :after_id";
                    $params[':after_id'] = $afterId;
                }
                $query .= " ORDER BY m.id ASC LIMIT 100";

                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } elseif (str_starts_with($channel, 'user_')) {
                $otherId = (int)str_replace('user_', '', $channel);

                // Fetch other user info
                $staffStmt = $db->prepare("
                    SELECT u.id, u.username, u.full_name, r.label AS role_label
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    WHERE u.id = :id
                    LIMIT 1
                ");
                $staffStmt->execute([':id' => $otherId]);
                $otherUser = $staffStmt->fetch(PDO::FETCH_ASSOC);

                if (!$otherUser) {
                    send_json(false, 'User not found', null, [], 404);
                }

                $channelInfo = [
                    'key'       => 'user_' . $otherId,
                    'name'      => $otherUser['full_name'],
                    'is_team'   => false,
                    'other_id'  => $otherId,
                    'role_label'=> $otherUser['role_label']
                ];

                // Fetch direct messages between user and otherId
                $query = "
                    SELECT m.id, m.sender_id, m.recipient_id, m.message, m.order_id, 
                           m.is_read, m.read_at, m.created_at,
                           u.full_name AS sender_name, u.username AS sender_username,
                           r.label AS sender_role,
                           o.order_number, o.order_status, o.total_amount
                    FROM chat_messages m
                    JOIN users u ON m.sender_id = u.id
                    JOIN roles r ON u.role_id = r.id
                    LEFT JOIN orders o ON m.order_id = o.id
                    WHERE ((m.sender_id = :uid1 AND m.recipient_id = :oid1)
                       OR (m.sender_id = :oid2 AND m.recipient_id = :uid2))
                ";
                $params = [
                    ':uid1' => $userId,
                    ':oid1' => $otherId,
                    ':oid2' => $otherId,
                    ':uid2' => $userId
                ];

                if ($afterId > 0) {
                    $query .= " AND m.id > :after_id";
                    $params[':after_id'] = $afterId;
                }
                $query .= " ORDER BY m.id ASC LIMIT 100";

                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Mark received unread messages from this sender as read
                $readStmt = $db->prepare("
                    UPDATE chat_messages 
                    SET is_read = 1, read_at = NOW() 
                    WHERE sender_id = :other_id 
                      AND recipient_id = :user_id 
                      AND is_read = 0
                ");
                $readStmt->execute([':other_id' => $otherId, ':user_id' => $userId]);
            } else {
                send_json(false, 'Invalid channel identifier', null, [], 400);
            }

            // Format message list for clean UI consumption
            $formatted = array_map(function ($m) use ($userId) {
                return [
                    'id'          => (int)$m['id'],
                    'is_me'       => ((int)$m['sender_id'] === $userId),
                    'sender_name' => $m['sender_name'],
                    'sender_role' => $m['sender_role'],
                    'message'     => $m['message'],
                    'created_at'  => $m['created_at'],
                    'time_formatted' => date('h:i A', strtotime($m['created_at'])),
                    'is_read'     => (bool)$m['is_read'],
                    'read_at'     => $m['read_at'],
                    'order'       => !empty($m['order_id']) ? [
                        'id'           => (int)$m['order_id'],
                        'order_number' => $m['order_number'],
                        'order_status' => $m['order_status'],
                        'total_amount' => (float)$m['total_amount'],
                        'total_formatted' => '₱ ' . number_format((float)$m['total_amount'], 2)
                    ] : null
                ];
            }, $messages);

            send_json(true, 'Messages loaded', [
                'channel'  => $channelInfo,
                'messages' => $formatted
            ]);
            break;
        }

        // -------------------------------------------------------------
        // 4. SEND MESSAGE
        // -------------------------------------------------------------
        case 'send': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'POST method required', null, [], 405);
            }

            // CSRF Verification
            $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!verify_csrf_token((string)$csrfToken)) {
                send_json(false, 'Security validation failed (CSRF token expired or invalid)', null, ['csrf' => 'Invalid token'], 403);
            }

            $messageText = trim((string)($_POST['message'] ?? ''));
            $channel = trim((string)($_POST['channel'] ?? ''));
            $orderId = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : null;

            if (empty($messageText)) {
                send_json(false, 'Message cannot be empty', null, ['message' => 'Required'], 422);
            }

            if (empty($channel)) {
                send_json(false, 'Destination channel is required', null, ['channel' => 'Required'], 422);
            }

            $recipientId = null;
            if ($channel !== 'team') {
                if (str_starts_with($channel, 'user_')) {
                    $recipientId = (int)str_replace('user_', '', $channel);
                } else {
                    $recipientId = (int)$channel;
                }

                // Verify recipient exists
                $chk = $db->prepare("SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1");
                $chk->execute([':id' => $recipientId]);
                if (!$chk->fetch()) {
                    send_json(false, 'Recipient user not found or inactive', null, ['recipient_id' => 'Invalid'], 404);
                }
            }

            // Verify order exists if orderId provided
            if ($orderId !== null) {
                $chkOrd = $db->prepare("SELECT id, order_number, order_status, total_amount FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1");
                $chkOrd->execute([':id' => $orderId]);
                $orderData = $chkOrd->fetch(PDO::FETCH_ASSOC);
                if (!$orderData) {
                    $orderId = null; // Clear invalid order attachment gracefully
                }
            }

            // Insert into chat_messages
            $insertStmt = $db->prepare("
                INSERT INTO chat_messages (sender_id, recipient_id, message, order_id, is_read, created_at)
                VALUES (:sender_id, :recipient_id, :message, :order_id, 0, NOW())
            ");
            $insertStmt->execute([
                ':sender_id'    => $userId,
                ':recipient_id' => $recipientId,
                ':message'      => $messageText,
                ':order_id'     => $orderId
            ]);

            $newMsgId = (int)$db->lastInsertId();

            send_json(true, 'Message sent successfully', [
                'id'              => $newMsgId,
                'is_me'           => true,
                'sender_name'     => $user['full_name'],
                'sender_role'     => $user['role_label'],
                'message'         => $messageText,
                'time_formatted'  => date('h:i A'),
                'order'           => isset($orderData) && $orderData ? [
                    'id'              => (int)$orderData['id'],
                    'order_number'    => $orderData['order_number'],
                    'order_status'    => $orderData['order_status'],
                    'total_amount'    => (float)$orderData['total_amount'],
                    'total_formatted' => '₱ ' . number_format((float)$orderData['total_amount'], 2)
                ] : null
            ]);
            break;
        }

        // -------------------------------------------------------------
        // 5. GET RECENT ORDERS FOR QUICK ATTACHMENT
        // -------------------------------------------------------------
        case 'recent_orders': {
            $stmt = $db->query("
                SELECT id, order_number, order_status, total_amount, customer_name, created_at
                FROM orders
                WHERE deleted_at IS NULL
                ORDER BY id DESC
                LIMIT 8
            ");
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = array_map(function ($o) {
                return [
                    'id'           => (int)$o['id'],
                    'order_number' => $o['order_number'],
                    'customer'     => $o['customer_name'],
                    'order_status' => $o['order_status'],
                    'total_formatted' => '₱ ' . number_format((float)$o['total_amount'], 2)
                ];
            }, $orders);

            send_json(true, 'Recent orders fetched', ['orders' => $formatted]);
            break;
        }

        default:
            send_json(false, 'Unknown or unspecified action', null, [], 400);
    }
} catch (Throwable $e) {
    error_log('[Chat Handler Error] ' . $e->getMessage());
    send_json(false, 'An error occurred while processing the chat request.', null, ['error' => $e->getMessage()], 500);
}
