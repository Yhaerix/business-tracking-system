<?php
/**
 * Internal Team Communication Drawer Component
 * Small Business Sales and Expense Tracking System
 *
 * Renders slide-out drawer matching new-design.html:
 *  - Conversation list (Staff members + #team channel)
 *  - Active thread view with bubbles, timestamps, order cards
 *  - Quick phrase pills tailored to user's role
 *  - Order attachment selector
 */

declare(strict_types=1);

$chatCurrentUser = current_user();
$chatRole = $chatCurrentUser['role_name'] ?? 'cashier';

// Role-tailored quick responses
$quickPhrases = [
    'cashier' => [
        'Need void approval',
        'Stock check please',
        'Order delivered',
        'Customer asking for discount'
    ],
    'manager' => [
        'Approved',
        'Please hold',
        'Check the stock ledger',
        'Check cash drawer'
    ],
    'admin' => [
        'Please send the report',
        'Well done',
        'Call me',
        'Approved'
    ]
];
$myQuickPhrases = $quickPhrases[$chatRole] ?? $quickPhrases['cashier'];
?>
<!-- Chat Modal Overlay & Slide-Out Drawer -->
<div class="modal-overlay" id="chatDrawer">
    <div class="modal-card">
        <div class="modal-header">
            <div class="chat-head">
                <button type="button" class="chat-back" id="chatBack" onclick="chatShowList()" aria-label="Back to conversations">
                    &lsaquo;
                </button>
                <div>
                    <h3 class="modal-title" id="chatTitle" style="margin:0;font-size:16px;">Messages</h3>
                    <small id="chatSubtitle" style="font-size:11px;color:var(--color-text-secondary);display:none;"></small>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeChat()" aria-label="Close messages">&times;</button>
        </div>

        <!-- View 1: Conversation List -->
        <div class="chat-list" id="chatList">
            <div style="padding: 24px; text-align: center; color: var(--color-text-secondary);">
                Loading conversations...
            </div>
        </div>

        <!-- View 2: Active Message Thread -->
        <div class="chat-thread" id="chatThread" style="display:none;">
            <!-- Message Bubbles Scroll Area -->
            <div class="chat-msgs" id="chatMsgs"></div>

            <!-- Role-Tailored Quick Phrases -->
            <div class="chat-quick" id="chatQuick">
                <?php foreach ($myQuickPhrases as $phrase): ?>
                    <button type="button" onclick="chatSendQuick(<?= htmlspecialchars(json_encode($phrase), ENT_QUOTES, 'UTF-8') ?>)">
                        <?= htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Recent Orders Attachment Row (Toggled by Paperclip) -->
            <div class="chat-quick" id="chatAttachRow" style="display:none;background:#eaf0fb;padding:8px 12px;border-radius:12px;margin:6px 12px 0;"></div>

            <!-- Message Input & Send Controls -->
            <div class="chat-compose">
                <button type="button" class="icon-btn" onclick="chatToggleAttach()" title="Attach recent order" aria-label="Attach recent order">
                    <svg viewBox="0 0 24 24"><path d="M20 11l-8 8a5 5 0 01-7-7l8-8a3.5 3.5 0 015 5l-8 8a2 2 0 01-3-3l7-7"/></svg>
                </button>
                <input type="text" id="chatInput" class="form-input" placeholder="Type a message..." autocomplete="off">
                <button type="button" class="btn btn-primary" id="chatSendBtn" onclick="chatSend()">
                    Send
                </button>
            </div>
        </div>
    </div>
</div>

