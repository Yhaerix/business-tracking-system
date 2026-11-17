<?php
/**
 * Public Customer Storefront Footer & Modals
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 */

declare(strict_types=1);
$currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';
?>
    <!-- 4-Pillar Trust Section -->
    <section class="trust-section" id="trustSection">
        <div class="store-container">
            <div class="trust-grid">
                <div class="trust-card">
                    <div class="trust-icon-box">🛡️</div>
                    <div class="trust-content">
                        <h3>100% Genuine Sealed</h3>
                        <p>Brand new in authentic Apple packaging with verifiable serial number.</p>
                    </div>
                </div>

                <div class="trust-card">
                    <div class="trust-icon-box">🎖️</div>
                    <div class="trust-content">
                        <h3>1-Year Apple Warranty</h3>
                        <p>Honored at all official Apple Authorized Service Providers nationwide.</p>
                    </div>
                </div>

                <div class="trust-card">
                    <div class="trust-icon-box">🚚</div>
                    <div class="trust-content">
                        <h3>Free Insured Shipping</h3>
                        <p>Complimentary door-to-door express transit with real-time tracking.</p>
                    </div>
                </div>

                <div class="trust-card">
                    <div class="trust-icon-box">🪙</div>
                    <div class="trust-content">
                        <h3>COD &amp; Coins Accepted</h3>
                        <p>Pay cash upon delivery or seamlessly redeem instant Prestige Apple Coins.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Footer -->
    <footer class="store-footer">
        <div class="store-container">
            <div class="footer-top-grid">
                <div class="footer-col">
                    <h4 style="color:#fff; font-size:16px;">Prestige Apple Store Philippines</h4>
                    <p style="margin-bottom:14px; line-height:1.5;">
                        Your premier authorized destination for brand-new and certified authentic Apple hardware, peripherals, and premium audio equipment.
                    </p>
                    <p style="font-size:12px;">Hotline: <strong>+63 (02) 8888-2775</strong> (9 AM – 8 PM Daily)</p>
                    <p style="font-size:12px;">Email: <strong>support@prestigeapple.ph</strong></p>
                </div>

                <div class="footer-col">
                    <h4>Apple Hardware</h4>
                    <ul>
                        <li><a href="javascript:void(0)" onclick="applyCategoryFilterByName('iPhone')">iPhone 16 Pro &amp; 16</a></li>
                        <li><a href="javascript:void(0)" onclick="applyCategoryFilterByName('MacBook')">MacBook Pro &amp; Air M3</a></li>
                        <li><a href="javascript:void(0)" onclick="applyCategoryFilterByName('iPad')">iPad Pro M4 &amp; Air</a></li>
                        <li><a href="javascript:void(0)" onclick="applyCategoryFilterByName('Watch')">Apple Watch Ultra 2 &amp; Series 10</a></li>
                        <li><a href="javascript:void(0)" onclick="applyCategoryFilterByName('AirPods')">AirPods Pro 2 &amp; Max</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Customer Care</h4>
                    <ul>
                        <li><a href="#flashDealsSection">Daily Flash Deals</a></li>
                        <li><a href="javascript:void(0)" onclick="openTopUpModal()">Prestige Coins Top-Up</a></li>
                        <li><a href="javascript:void(0)">Check Order Status</a></li>
                        <li><a href="javascript:void(0)">AppleCare+ Registration</a></li>
                        <li><a href="javascript:void(0)">Warranty Claims Procedure</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Security &amp; Compliance</h4>
                    <p style="font-size:11.5px; margin-bottom:10px;">
                        All customer payments, COD verification, and coin balances are encrypted and guarded by bank-grade TLS.
                    </p>
                    <div style="display:flex; gap:8px; font-size:22px;">
                        <span>💳</span><span>📱</span><span>🔒</span><span>🇵🇭</span>
                    </div>
                </div>
            </div>

            <div class="footer-bottom">
                <div>
                    &copy; <?= date('Y') ?> Prestige Apple Store Philippines. All rights reserved. Apple, iPhone, MacBook, and iPad are trademarks of Apple Inc.
                </div>
                <div style="display:flex; gap:16px;">
                    <a href="javascript:void(0)">Privacy Policy</a>
                    <a href="javascript:void(0)">Terms of Sale</a>
                    <a href="javascript:void(0)">Shipping &amp; Delivery Terms</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- ====================================================================
         PRESTIGE COINS TOP-UP MODAL (#topUpModal)
         ==================================================================== -->
    <div class="store-modal-overlay" id="topUpModal" role="dialog" aria-modal="true" aria-labelledby="topUpModalTitle">
        <div class="store-modal-card" style="max-width: 440px; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.15); border: none; overflow: hidden;">
            <div class="store-modal-header" style="border-bottom: none; padding: 22px 24px 8px 24px;">
                <div class="store-modal-title" id="topUpModalTitle" style="font-size: 19px; font-weight: 800; color: var(--apple-dark);">
                    Top Up Coins
                </div>
                <button type="button" class="store-modal-close" onclick="closeTopUpModal()" aria-label="Close modal">&times;</button>
            </div>
            <form id="topUpForm" onsubmit="submitCoinsTopUp(event)">
                <div class="store-modal-body" style="padding: 10px 24px 24px 24px;">
                    <!-- Balance Summary -->
                    <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 12px 16px; border-radius: 12px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                        <span style="font-size: 13px; color: var(--apple-gray); font-weight: 500;">Current Balance</span>
                        <span class="wallet-balance-num" id="modalWalletBalance" style="font-size: 17px; font-weight: 800; color: var(--apple-dark); margin: 0;"><?= $formattedCoins ?></span>
                    </div>

                    <!-- Amount Selection -->
                    <div style="margin-bottom: 16px;">
                        <label for="customTopUpAmount" style="display: block; font-size: 12.5px; font-weight: 700; color: var(--apple-dark); margin-bottom: 8px;">
                            Select Amount
                        </label>
                        <div class="preset-chips-grid" style="margin-bottom: 10px;">
                            <div class="preset-chip" onclick="selectPresetAmount(500)">₱500</div>
                            <div class="preset-chip" onclick="selectPresetAmount(1000)">₱1,000</div>
                            <div class="preset-chip" onclick="selectPresetAmount(2500)">₱2,500</div>
                            <div class="preset-chip active" onclick="selectPresetAmount(5000)">₱5,000</div>
                        </div>
                        <div class="custom-amount-wrap">
                            <span class="currency-affix"><?= $currency ?></span>
                            <input type="number" id="customTopUpAmount" class="custom-amount-input" 
                                   value="5000" placeholder="Custom amount" min="1" max="1000000" step="any" required oninput="updateTopUpPreview()">
                        </div>
                    </div>

                    <!-- Payment Method Toggle -->
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 12.5px; font-weight: 700; color: var(--apple-dark); margin-bottom: 8px;">
                            Payment Method
                        </label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px;">
                            <button type="button" class="payment-method-card active" id="tabGcash" onclick="selectPaymentMethod('GCASH')" style="padding: 10px; border-radius: 10px; border: none; font-size: 13px; font-weight: 700; cursor: pointer; text-align: center; transition: all 0.15s ease; background: #eff6ff; color: var(--apple-blue); box-shadow: 0 0 0 2px var(--apple-blue);">
                                GCash
                            </button>
                            <button type="button" class="payment-method-card" id="tabBank" onclick="selectPaymentMethod('BANK_TRANSFER')" style="padding: 10px; border-radius: 10px; border: none; font-size: 13px; font-weight: 600; cursor: pointer; text-align: center; transition: all 0.15s ease; background: #ffffff; color: var(--apple-dark); box-shadow: 0 1px 4px rgba(0,0,0,0.06);">
                                Bank Transfer
                            </button>
                        </div>

                        <!-- GCash Account Details -->
                        <div id="channelDetailsGcash" style="background: #f8fafc; border-radius: 10px; padding: 12px 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="font-size: 11.5px; color: var(--apple-gray);">Prestige Apple Store PH</div>
                                    <div style="font-size: 15px; font-weight: 800; font-family: monospace; color: var(--apple-dark);">0917-888-2775</div>
                                </div>
                                <button type="button" onclick="copyToClipboard('09178882775', 'GCash number copied!')" style="font-size: 11.5px; padding: 5px 12px; border-radius: 9999px; border: none; background: #ffffff; box-shadow: 0 1px 4px rgba(0,0,0,0.08); cursor: pointer; font-weight: 600; color: var(--apple-dark);">
                                    Copy
                                </button>
                            </div>
                        </div>

                        <!-- Bank Account Details -->
                        <div id="channelDetailsBank" style="display: none; background: #f8fafc; border-radius: 10px; padding: 12px 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="font-size: 11.5px; color: var(--apple-gray);">BDO &bull; Prestige Apple Gadgets</div>
                                    <div style="font-size: 15px; font-weight: 800; font-family: monospace; color: var(--apple-dark);">0048-2801-9452</div>
                                </div>
                                <button type="button" onclick="copyToClipboard('004828019452', 'Bank number copied!')" style="font-size: 11.5px; padding: 5px 12px; border-radius: 9999px; border: none; background: #ffffff; box-shadow: 0 1px 4px rgba(0,0,0,0.08); cursor: pointer; font-weight: 600; color: var(--apple-dark);">
                                    Copy
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Reference # Input -->
                    <div style="margin-bottom: 16px;">
                        <label for="topUpRefNumber" style="display: block; font-size: 12.5px; font-weight: 700; color: var(--apple-dark); margin-bottom: 6px;">
                            Reference Number
                        </label>
                        <input type="text" id="topUpRefNumber" class="store-form-input" 
                               placeholder="e.g. 13-digit Reference #" autocomplete="off"
                               style="width: 100%; height: 42px; font-size: 13.5px; border-radius: 10px; border: none; background: #f8fafc; box-shadow: inset 0 1px 3px rgba(0,0,0,0.05); padding: 0 14px; font-family: monospace; text-transform: uppercase;">
                    </div>

                    <!-- Projected Balance Row -->
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12.5px; color: var(--apple-gray); margin-bottom: 16px; padding: 0 2px;">
                        <span>Projected Balance</span>
                        <strong id="newBalancePreview" style="color: var(--apple-dark); font-size: 13.5px; font-weight: 700;"><?= $formattedCoins ?></strong>
                    </div>

                    <button type="submit" class="topup-submit-btn" id="topUpSubmitBtn" style="height: 46px; border-radius: 12px; font-size: 14.5px; font-weight: 700; box-shadow: 0 4px 14px rgba(0, 113, 227, 0.25); background: var(--apple-blue);">
                        Confirm Top Up
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ====================================================================
         PRESTIGE APPLE STORE LIVE CUSTOMER CHAT WIDGET
         ==================================================================== -->
    <div id="storeChatFloatingBtn" onclick="toggleStoreChat()" title="Message Prestige Apple Support" style="position:fixed;bottom:24px;right:24px;width:56px;height:56px;border-radius:50%;background:#0071e3;color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(0,113,227,0.35);cursor:pointer;z-index:9998;transition:transform 0.2s cubic-bezier(0.16,1,0.3,1);">
        <svg viewBox="0 0 24 24" width="26" height="26" style="stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><path d="M21 12a8 8 0 01-11.6 7.1L4 20l1-4.6A8 8 0 1121 12z"/></svg>
        <span id="storeChatUnreadBadge" style="display:none;position:absolute;top:-2px;right:-2px;background:#ef4444;color:#fff;border-radius:10px;padding:2px 7px;font-size:11px;font-weight:800;border:2px solid #fff;">0</span>
    </div>

    <!-- Store Live Chat Window -->
    <div id="storeChatBox" style="display:none;position:fixed;bottom:90px;right:24px;width:370px;max-width:calc(100vw - 32px);height:520px;max-height:calc(100vh - 120px);background:#ffffff;border-radius:20px;box-shadow:0 18px 45px rgba(0,0,0,0.18);z-index:9999;flex-direction:column;overflow:hidden;border:1px solid #e2e8f0;font-family:-apple-system,BlinkMacSystemFont,'Inter',sans-serif;">
        <!-- Chat Header -->
        <div style="background:#0071e3;color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;font-size:18px;">🍎</div>
                <div>
                    <div style="font-weight:700;font-size:14px;line-height:1.2;">Prestige Store Support</div>
                    <div style="font-size:11px;opacity:0.9;display:flex;align-items:center;gap:4px;">
                        <span style="width:6px;height:6px;border-radius:50%;background:#4ade80;"></span> Online &bull; Stock &amp; Order Inquiries
                    </div>
                </div>
            </div>
            <button type="button" onclick="toggleStoreChat()" style="background:none;border:none;color:#fff;font-size:22px;line-height:1;cursor:pointer;padding:0 4px;" aria-label="Close Chat">&times;</button>
        </div>

        <?php if (!empty($_SESSION['customer_id'])): ?>
            <!-- Chat Messages Feed -->
            <div id="storeChatMessages" style="flex:1;overflow-y:auto;padding:14px;background:#f8fafc;display:flex;flex-direction:column;gap:10px;font-size:13px;">
                <div style="text-align:center;color:#64748b;font-size:12px;padding:20px 0;">Loading conversation...</div>
            </div>

            <!-- Quick Question Chips -->
            <div style="padding:6px 12px;background:#fff;border-top:1px solid #f1f5f9;display:flex;gap:6px;overflow-x:auto;white-space:nowrap;flex-shrink:0;">
                <button type="button" onclick="sendStoreQuickMsg('Hi! Is iPhone 16 Pro Max available in stock?')" style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:14px;padding:4px 10px;font-size:11px;color:#334155;cursor:pointer;flex-shrink:0;">📦 Check Stock</button>
                <button type="button" onclick="sendStoreQuickMsg('When is the estimated delivery time?')" style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:14px;padding:4px 10px;font-size:11px;color:#334155;cursor:pointer;flex-shrink:0;">🚚 Delivery Time</button>
                <button type="button" onclick="sendStoreQuickMsg('How does the 1-year Apple Warranty work?')" style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:14px;padding:4px 10px;font-size:11px;color:#334155;cursor:pointer;flex-shrink:0;">🛡️ Apple Warranty</button>
            </div>

            <!-- Chat Input Form -->
            <form id="storeChatForm" onsubmit="submitStoreChatMessage(event)" style="padding:10px 12px;background:#ffffff;border-top:1px solid #e2e8f0;display:flex;gap:8px;align-items:center;flex-shrink:0;">
                <input type="hidden" id="storeChatProductId" value="">
                <input type="text" id="storeChatInput" placeholder="Type a message or inquiry..." autocomplete="off" style="flex:1;height:38px;padding:0 12px;border:1px solid #cbd5e1;border-radius:20px;font-size:13px;outline:none;">
                <button type="submit" id="storeChatSendBtn" style="height:38px;padding:0 16px;background:#0071e3;color:#fff;border:none;border-radius:20px;font-size:13px;font-weight:600;cursor:pointer;">Send</button>
            </form>
        <?php else: ?>
            <!-- Guest Prompt: Sign In Required -->
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;text-align:center;background:#f8fafc;">
                <div style="font-size:36px;margin-bottom:12px;">💬</div>
                <h4 style="margin:0 0 6px 0;font-size:16px;color:#0f172a;">Sign in to Chat with Us</h4>
                <p style="font-size:12.5px;color:#64748b;margin:0 0 16px 0;line-height:1.4;">Sign in to your Prestige customer account to message our staff about available stock, order updates, and technical assistance.</p>
                <a href="login.php?redirect=index.php" style="background:#0071e3;color:#fff;padding:9px 20px;border-radius:20px;text-decoration:none;font-weight:600;font-size:13px;display:inline-block;">
                    Sign In or Register
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
    // Store Customer Live Chat Controller
    let isStoreChatOpen = false;
    let storeChatPollTimer = null;

    function toggleStoreChat() {
        const box = document.getElementById('storeChatBox');
        if (!box) return;
        isStoreChatOpen = !isStoreChatOpen;
        box.style.display = isStoreChatOpen ? 'flex' : 'none';

        if (isStoreChatOpen) {
            loadStoreChatMessages(true);
            startStoreChatPolling();
            const input = document.getElementById('storeChatInput');
            if (input) input.focus();
        } else {
            stopStoreChatPolling();
            refreshStoreChatUnread();
        }
    }

    function openStoreChatWithProduct(productId, defaultText) {
        const box = document.getElementById('storeChatBox');
        if (!box) return;
        if (!isStoreChatOpen) toggleStoreChat();
        const pIdInput = document.getElementById('storeChatProductId');
        const input = document.getElementById('storeChatInput');
        if (pIdInput) pIdInput.value = productId || '';
        if (input) {
            input.value = defaultText || '';
            input.focus();
        }
    }

    function loadStoreChatMessages(scrollToBottom = false) {
        const feed = document.getElementById('storeChatMessages');
        if (!feed) return;

        fetch('handler.php?action=get_my_messages')
            .then(res => res.json())
            .then(res => {
                if (!res.success || !res.data) {
                    feed.innerHTML = '<div style="text-align:center;color:#ef4444;padding:20px 0;">Sign in to view messages</div>';
                    return;
                }

                const msgs = res.data.messages || [];
                if (msgs.length === 0) {
                    feed.innerHTML = `
                        <div style="text-align:center;color:#64748b;margin:auto;padding:24px;">
                            <div style="font-size:32px;margin-bottom:8px;">👋</div>
                            <div style="font-weight:700;color:#0f172a;">Welcome to Prestige Support</div>
                            <div style="font-size:12px;margin-top:4px;">Ask us about product availability, stock status, or your orders!</div>
                        </div>
                    `;
                    return;
                }

                feed.innerHTML = msgs.map(m => {
                    const mine = m.is_me;
                    const prodHtml = m.product ? `
                        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:6px 10px;margin-bottom:5px;font-size:11.5px;text-align:left;">
                            <div style="font-weight:700;color:#1e40af;">🍎 ${m.product.name}</div>
                            <div style="color:#2563eb;font-size:11px;">Price: ${m.product.price} &bull; Stock: ${m.product.stock > 0 ? m.product.stock + ' in stock' : 'Out of stock'}</div>
                        </div>
                    ` : '';

                    return `
                        <div style="display:flex;flex-direction:column;align-self:${mine ? 'flex-end' : 'flex-start'};max-width:80%;">
                            <div style="background:${mine ? '#0071e3' : '#ffffff'};color:${mine ? '#ffffff' : '#1e293b'};padding:8px 12px;border-radius:${mine ? '16px 16px 4px 16px' : '16px 16px 16px 4px'};box-shadow:0 1px 3px rgba(0,0,0,0.06);line-height:1.4;">
                                ${prodHtml}
                                <div>${m.message}</div>
                            </div>
                            <small style="font-size:10px;color:#94a3b8;margin-top:3px;align-self:${mine ? 'flex-end' : 'flex-start'};">${m.time_formatted}</small>
                        </div>
                    `;
                }).join('');

                if (scrollToBottom) {
                    feed.scrollTop = feed.scrollHeight;
                }
                refreshStoreChatUnread();
            })
            .catch(() => {});
    }

    function submitStoreChatMessage(e) {
        e.preventDefault();
        const input = document.getElementById('storeChatInput');
        const pIdInput = document.getElementById('storeChatProductId');
        const sendBtn = document.getElementById('storeChatSendBtn');
        if (!input) return;
        const text = input.value.trim();
        if (!text) return;

        if (sendBtn) sendBtn.disabled = true;

        const payload = new URLSearchParams();
        payload.append('action', 'send_message_to_store');
        payload.append('message', text);
        if (pIdInput && pIdInput.value) {
            payload.append('product_id', pIdInput.value);
        }

        fetch('handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        })
            .then(res => res.json())
            .then(res => {
                if (sendBtn) sendBtn.disabled = false;
                if (res.success) {
                    input.value = '';
                    if (pIdInput) pIdInput.value = '';
                    loadStoreChatMessages(true);
                } else {
                    alert(res.message || 'Failed to send message');
                }
            })
            .catch(() => {
                if (sendBtn) sendBtn.disabled = false;
            });
    }

    function sendStoreQuickMsg(text) {
        const input = document.getElementById('storeChatInput');
        if (input) {
            input.value = text;
            const form = document.getElementById('storeChatForm');
            if (form) form.requestSubmit();
        }
    }

    function refreshStoreChatUnread() {
        fetch('handler.php?action=get_chat_unread_count')
            .then(res => res.json())
            .then(res => {
                if (!res.success || !res.data) return;
                const count = res.data.unread || 0;
                const badge = document.getElementById('storeChatUnreadBadge');
                if (badge) {
                    badge.textContent = count;
                    badge.style.display = count > 0 ? 'inline-block' : 'none';
                }
            })
            .catch(() => {});
    }

    function startStoreChatPolling() {
        stopStoreChatPolling();
        storeChatPollTimer = setInterval(() => {
            loadStoreChatMessages(false);
        }, 5000);
    }

    function stopStoreChatPolling() {
        if (storeChatPollTimer) {
            clearInterval(storeChatPollTimer);
            storeChatPollTimer = null;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        refreshStoreChatUnread();
        setInterval(refreshStoreChatUnread, 30000);
    });
    </script>

    <!-- Centralized Client Logic Script -->
    <script src="../assets/js/store.js?v=<?= time() ?>"></script>
</body>
</html>