/**
 * Internal Team Communication & Chat Controller
 * Small Business Sales and Expense Tracking System
 * Modern Design System (assets/js/chat.js)
 */

(function () {
    'use strict';

    let currentChannel = null;
    let activePollTimer = null;
    let cachedOrders = [];
    let isSending = false;

    // Helper: Safely escape HTML text
    function escapeHtml(str) {
        if (!str) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(str).replace(/[&<>"']/g, m => map[m]);
    }

    // Get active CSRF token from meta header
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // -------------------------------------------------------------
    // Open & Close Drawer
    // -------------------------------------------------------------
    window.openChat = function (channelKey) {
        const profileMenu = document.getElementById('profileMenu');
        if (profileMenu) profileMenu.classList.remove('open');

        const drawer = document.getElementById('chatDrawer');
        if (drawer) {
            drawer.classList.add('active');
            document.body.classList.add('modal-open');
        }

        if (channelKey) {
            window.chatOpen(channelKey);
        } else {
            window.chatShowList();
        }

        // Start frequent polling while drawer is open
        startChatPolling();
    };

    window.closeChat = function () {
        const drawer = document.getElementById('chatDrawer');
        if (drawer) {
            drawer.classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        currentChannel = null;
        stopChatPolling();
        window.chatRefreshUnread();
    };

    // -------------------------------------------------------------
    // Conversation List View
    // -------------------------------------------------------------
    window.chatShowList = function () {
        currentChannel = null;
        const title = document.getElementById('chatTitle');
        const sub = document.getElementById('chatSubtitle');
        const backBtn = document.getElementById('chatBack');
        const list = document.getElementById('chatList');
        const thread = document.getElementById('chatThread');

        if (title) title.textContent = 'Messages';
        if (sub) sub.style.display = 'none';
        if (backBtn) backBtn.style.display = 'none';
        if (thread) thread.style.display = 'none';
        if (list) list.style.display = 'block';

        fetch(window.BASE_URL + '/modules/chat/handler.php?action=conversations')
            .then(res => res.json())
            .then(res => {
                if (!res.success || !res.data) {
                    list.innerHTML = `<div style="padding:20px;text-align:center;color:var(--color-danger);">${escapeHtml(res.message || 'Failed to load conversations')}</div>`;
                    return;
                }

                const convs = res.data.conversations || [];
                if (convs.length === 0) {
                    list.innerHTML = `<div style="padding:24px;text-align:center;color:var(--color-text-secondary);">No conversations found</div>`;
                    return;
                }

                const teamStaff = convs.filter(c => !c.is_customer);
                const customers = convs.filter(c => c.is_customer);

                let html = '';

                // 1. Team & Staff Section
                if (teamStaff.length > 0) {
                    html += `<div class="chat-section-header">Internal Team &amp; Staff</div>`;
                    teamStaff.forEach(c => {
                        const isTeam = c.is_team;
                        const unreadBadge = c.unread > 0 ? `<span class="chat-unread">${c.unread}</span>` : '';
                        const timeStr = c.last_time ? new Date(c.last_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
                        const senderPrefix = c.last_sender ? `${escapeHtml(c.last_sender)}: ` : '';

                        html += `
                            <div class="chat-row" onclick="chatOpen('${escapeHtml(c.key)}', '${escapeHtml(c.name)}', '${escapeHtml(c.role_label)}')">
                                <div class="chat-av ${isTeam ? 'team' : ''}">
                                    ${escapeHtml(c.initials)}
                                    ${!isTeam ? '<i></i>' : ''}
                                </div>
                                <div class="chat-meta">
                                    <div class="chat-name">
                                        <span>${escapeHtml(c.name)}</span>
                                        <small>${timeStr}</small>
                                    </div>
                                    <div class="chat-last">
                                        ${senderPrefix}${escapeHtml(c.last_message)}
                                    </div>
                                </div>
                                ${unreadBadge}
                            </div>
                        `;
                    });
                }

                // 2. Customer Inquiries & Users Section with Clean Divider
                html += `
                    <div class="chat-divider-wrap">
                        <div class="chat-divider-line"></div>
                        <span class="chat-divider-text">Customer Inquiries &amp; Users</span>
                        <div class="chat-divider-line"></div>
                    </div>
                `;

                if (customers.length === 0) {
                    html += `
                        <div style="padding:16px 12px;text-align:center;color:var(--text-muted);font-size:12px;">
                            No customer inquiries yet. Customer messages from the storefront will appear here.
                        </div>
                    `;
                } else {
                    customers.forEach(c => {
                        const unreadBadge = c.unread > 0 ? `<span class="chat-unread" style="background:#2563eb;">${c.unread}</span>` : '';
                        const timeStr = c.last_time ? new Date(c.last_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
                        const senderPrefix = c.last_sender ? `${escapeHtml(c.last_sender)}: ` : '';

                        html += `
                            <div class="chat-row" onclick="chatOpen('${escapeHtml(c.key)}', '${escapeHtml(c.name)}', 'Store Customer')">
                                <div class="chat-av customer">
                                    ${escapeHtml(c.initials)}
                                </div>
                                <div class="chat-meta">
                                    <div class="chat-name">
                                        <span>${escapeHtml(c.name)} <span class="chat-badge-customer">Customer</span></span>
                                        <small>${timeStr}</small>
                                    </div>
                                    <div class="chat-last">
                                        ${senderPrefix}${escapeHtml(c.last_message)}
                                    </div>
                                </div>
                                ${unreadBadge}
                            </div>
                        `;
                    });
                }

                list.innerHTML = html;
                window.chatRefreshUnread();
            })
            .catch(err => {
                list.innerHTML = `<div style="padding:20px;text-align:center;color:var(--color-text-secondary);">Unable to connect to chat service</div>`;
            });
    };

    // -------------------------------------------------------------
    // Open Specific Thread
    // -------------------------------------------------------------
    window.chatOpen = function (channelKey, channelName, channelRole) {
        currentChannel = channelKey;
        const title = document.getElementById('chatTitle');
        const sub = document.getElementById('chatSubtitle');
        const backBtn = document.getElementById('chatBack');
        const list = document.getElementById('chatList');
        const thread = document.getElementById('chatThread');
        const attachRow = document.getElementById('chatAttachRow');

        if (title) title.textContent = channelName || 'Chat';
        if (sub) {
            sub.textContent = channelRole || '';
            sub.style.display = channelRole ? 'block' : 'none';
        }
        if (backBtn) backBtn.style.display = 'inline-block';
        if (list) list.style.display = 'none';
        if (thread) thread.style.display = 'flex';
        if (attachRow) attachRow.style.display = 'none';

        // Focus message input
        const input = document.getElementById('chatInput');
        if (input) {
            input.value = '';
            input.focus();
        }

        loadThreadMessages(channelKey, true);
    };

    // -------------------------------------------------------------
    // Load & Render Messages
    // -------------------------------------------------------------
    function loadThreadMessages(channelKey, scrollToBottom = false) {
        if (!channelKey) return;

        fetch(`${window.BASE_URL}/modules/chat/handler.php?action=messages&channel=${encodeURIComponent(channelKey)}`)
            .then(res => res.json())
            .then(res => {
                if (!res.success || !res.data) return;

                const msgsBox = document.getElementById('chatMsgs');
                if (!msgsBox) return;

                const msgs = res.data.messages || [];
                const channel = res.data.channel || {};

                if (msgs.length === 0) {
                    msgsBox.innerHTML = `
                        <div style="text-align:center;color:var(--color-text-secondary);margin:auto;padding:24px;">
                            <div style="font-size:32px;margin-bottom:8px;">👋</div>
                            <div style="font-weight:600;">No messages yet</div>
                            <div style="font-size:12px;margin-top:4px;">Say hello or use one of the quick replies below!</div>
                        </div>
                    `;
                    return;
                }

                let html = '';
                msgs.forEach(m => {
                    const mine = m.is_me;
                    const orderHtml = m.order ? `
                        <div class="msg-order" onclick="chatNavigateOrder(${m.order.id})" title="View order details">
                            <svg class="ico" viewBox="0 0 24 24" width="18" height="18" style="stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;">
                                <path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2zM9 7h6M9 11h6"/>
                            </svg>
                            <div>
                                <b>${escapeHtml(m.order.order_number)}</b>
                                <div style="font-size:11px;opacity:0.9;">
                                    ${escapeHtml(m.order.order_status)} &bull; ${escapeHtml(m.order.total_formatted)}
                                </div>
                            </div>
                        </div>
                    ` : '';

                    const productHtml = m.product ? `
                        <div class="msg-product-card">
                            <span style="font-size:22px;line-height:1;">🍎</span>
                            <div style="flex:1;">
                                <div style="font-weight:700;color:#166534;">${escapeHtml(m.product.name)}</div>
                                <div style="color:#15803d;font-size:11px;margin-top:2px;">
                                    Price: <b>${escapeHtml(m.product.price_formatted)}</b> &bull; Stock: <b style="color:${m.product.stock > 0 ? '#15803d' : '#dc2626'};">${m.product.stock} units available</b>
                                </div>
                            </div>
                        </div>
                    ` : '';

                    const seenStatus = (mine && !channel.is_team && m.is_read) ? ' &bull; Seen' : '';

                    html += `
                        <div class="msg ${mine ? 'me' : ''}">
                            ${!mine && channel.is_team ? `<div class="who">${escapeHtml(m.sender_name)} (${escapeHtml(m.sender_role)})</div>` : ''}
                            ${productHtml}
                            <div class="text">${escapeHtml(m.message)}</div>
                            ${orderHtml}
                            <small>${escapeHtml(m.time_formatted)}${seenStatus}</small>
                        </div>
                    `;
                });

                msgsBox.innerHTML = html;

                if (scrollToBottom) {
                    msgsBox.scrollTop = msgsBox.scrollHeight;
                }
            })
            .catch(() => {});
    }

    // -------------------------------------------------------------
    // Send Message
    // -------------------------------------------------------------
    window.chatSend = function (overrideText, attachedOrderId) {
        if (!currentChannel || isSending) return;

        const input = document.getElementById('chatInput');
        const text = (overrideText !== undefined ? overrideText : (input ? input.value : '')).trim();

        if (!text) return;

        isSending = true;
        const sendBtn = document.getElementById('chatSendBtn');
        if (sendBtn) sendBtn.disabled = true;

        const payload = new URLSearchParams();
        payload.append('action', 'send');
        payload.append('channel', currentChannel);
        payload.append('message', text);
        payload.append('csrf_token', getCsrfToken());
        if (attachedOrderId) {
            payload.append('order_id', attachedOrderId);
        }

        fetch(window.BASE_URL + '/modules/chat/handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: payload.toString()
        })
            .then(res => res.json())
            .then(res => {
                isSending = false;
                if (sendBtn) sendBtn.disabled = false;

                if (res.success) {
                    if (input) input.value = '';
                    const attachRow = document.getElementById('chatAttachRow');
                    if (attachRow) attachRow.style.display = 'none';

                    loadThreadMessages(currentChannel, true);
                    window.chatRefreshUnread();
                } else {
                    alert(res.message || 'Failed to send message');
                }
            })
            .catch(() => {
                isSending = false;
                if (sendBtn) sendBtn.disabled = false;
                alert('Network connection error while sending message.');
            });
    };

    window.chatSendQuick = function (phrase) {
        window.chatSend(phrase);
    };

    // -------------------------------------------------------------
    // Order Attachment
    // -------------------------------------------------------------
    window.chatToggleAttach = function () {
        const attachRow = document.getElementById('chatAttachRow');
        if (!attachRow) return;

        if (attachRow.style.display === 'flex') {
            attachRow.style.display = 'none';
            return;
        }

        attachRow.style.display = 'flex';
        attachRow.innerHTML = '<span style="font-size:12px;color:var(--color-text-secondary);">Loading recent orders...</span>';

        fetch(window.BASE_URL + '/modules/chat/handler.php?action=recent_orders')
            .then(res => res.json())
            .then(res => {
                if (!res.success || !res.data || !res.data.orders || res.data.orders.length === 0) {
                    attachRow.innerHTML = '<span style="font-size:12px;color:var(--color-text-secondary);">No orders found to attach</span>';
                    return;
                }

                cachedOrders = res.data.orders;
                let html = '<span style="font-size:11px;font-weight:700;color:var(--color-primary);width:100%;margin-bottom:4px;">Attach Order:</span>';
                cachedOrders.forEach(o => {
                    html += `
                        <button type="button" onclick="chatSendAttachedOrder(${o.id}, '${escapeHtml(o.order_number)}')" title="Customer: ${escapeHtml(o.customer)} (${escapeHtml(o.total_formatted)})">
                            📦 ${escapeHtml(o.order_number)} &bull; ${escapeHtml(o.total_formatted)}
                        </button>
                    `;
                });
                attachRow.innerHTML = html;
            })
            .catch(() => {
                attachRow.innerHTML = '<span style="font-size:12px;color:var(--color-danger);">Failed to load orders</span>';
            });
    };

    window.chatSendAttachedOrder = function (orderId, orderNum) {
        window.chatSend(`Please review order ${orderNum}`, orderId);
    };

    window.chatNavigateOrder = function (orderId) {
        window.closeChat();
        window.location.href = window.BASE_URL + '/modules/sales/?order_id=' + encodeURIComponent(orderId);
    };

    // -------------------------------------------------------------
    // Unread Counter Polling & Badges
    // -------------------------------------------------------------
    window.chatRefreshUnread = function () {
        fetch(window.BASE_URL + '/modules/chat/handler.php?action=unread_count')
            .then(res => res.json())
            .then(res => {
                if (!res.success || !res.data) return;

                const count = res.data.unread || 0;
                const badge = document.getElementById('chatBadge');
                const topbarBadge = document.getElementById('topbarChatBadge');
                const chip = document.querySelector('.profile-chip');

                if (badge) {
                    badge.textContent = count;
                    badge.classList.toggle('show', count > 0);
                }

                if (topbarBadge) {
                    topbarBadge.textContent = count;
                    topbarBadge.style.display = count > 0 ? 'inline-block' : 'none';
                }

                if (chip) {
                    chip.classList.toggle('has-unread', count > 0);
                }
            })
            .catch(() => {});
    };

    function startChatPolling() {
        stopChatPolling();
        activePollTimer = setInterval(() => {
            if (currentChannel) {
                loadThreadMessages(currentChannel, false);
            }
            window.chatRefreshUnread();
        }, 8000);
    }

    function stopChatPolling() {
        if (activePollTimer) {
            clearInterval(activePollTimer);
            activePollTimer = null;
        }
    }

    // -------------------------------------------------------------
    // Initialization on Page Load
    // -------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        // Initial badge refresh
        window.chatRefreshUnread();

        // Listen for Enter key on chat input
        const chatInput = document.getElementById('chatInput');
        if (chatInput) {
            chatInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    window.chatSend();
                }
            });
        }

        // Periodic background poll every 30 seconds
        setInterval(window.chatRefreshUnread, 30000);
    });

})();

