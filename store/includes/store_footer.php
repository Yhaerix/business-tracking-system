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

    <!-- Centralized Client Logic Script -->
    <script src="../assets/js/store.js?v=<?= time() ?>"></script>
</body>
</html>