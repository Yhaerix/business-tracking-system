<?php
/**
 * System Settings, Audit Trails & Operational Reminder Scheduler
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

$pageTitle = 'System Settings & Reminders';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_permission('settings_view');

$canManage = has_permission('settings_manage');
$db = get_db();

// Pre-load store settings for instant initial SSR rendering
$settingsDefaults = [
    'store_name'              => 'Prestige Apple Panel',
    'contact_email'           => 'support@prestigeapple.ph',
    'contact_phone'           => '+63 917 800 2775',
    'currency_symbol'         => '₱',
    'default_low_stock_alert' => '5'
];

$storeSettings = $settingsDefaults;
$lastUpdatedTimestamp = null;

try {
    $stmtSettings = $db->query("SELECT setting_key, setting_value, updated_at FROM store_settings");
    $settingsRows = $stmtSettings->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settingsRows as $row) {
        $storeSettings[$row['setting_key']] = $row['setting_value'];
        if ($lastUpdatedTimestamp === null || ($row['updated_at'] && $row['updated_at'] > $lastUpdatedTimestamp)) {
            $lastUpdatedTimestamp = $row['updated_at'];
        }
    }
} catch (Throwable $e) {
    error_log('[Settings View Error] ' . $e->getMessage());
}

// Pre-load reminder summary counts for SSR (and seed baseline incoming delivery reminders if empty)
$remindersSummary = ['total' => 0, 'active' => 0, 'due' => 0, 'completed' => 0];
try {
    $totalRemCount = (int) $db->query("SELECT COUNT(*) FROM reminders")->fetchColumn();
    if ($totalRemCount === 0) {
        $seedUid = (int) ($_SESSION['user_id'] ?? 1);
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

    $stmtRem = $db->query("
        SELECT is_active, is_completed, target_date 
        FROM reminders
    ");
    $remRows = $stmtRem->fetchAll(PDO::FETCH_ASSOC);
    $nowTs = time();
    foreach ($remRows as $r) {
        $remindersSummary['total']++;
        if ((int)$r['is_completed'] === 1) {
            $remindersSummary['completed']++;
        } elseif ((int)$r['is_active'] === 1) {
            $remindersSummary['active']++;
            $targetTs = strtotime((string)$r['target_date']);
            if ($targetTs !== false && $targetTs <= $nowTs) {
                $remindersSummary['due']++;
            }
        }
    }
} catch (Throwable $e) {
    error_log('[Reminders View Error] ' . $e->getMessage());
}

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ==========================================================================
   System Settings & Tabs Component Styles
   ========================================================================== */
.settings-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}

.settings-header h1 {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-main);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.settings-header p {
    font-size: 13.5px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}

/* Tab Navigation Bar */
.settings-tabs-nav {
    display: flex;
    gap: 4px;
    border-bottom: 2px solid var(--border-color);
    margin-bottom: 24px;
    overflow-x: auto;
    background: transparent;
    padding-bottom: 0;
}

.settings-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
    text-decoration: none;
    border-top-left-radius: var(--radius-md);
    border-top-right-radius: var(--radius-md);
}

.settings-tab-btn svg {
    width: 17px;
    height: 17px;
    stroke-width: 2.1;
    stroke: currentColor;
    fill: none;
    flex-shrink: 0;
}

.settings-tab-btn:hover {
    color: var(--color-primary);
    background: var(--color-primary-light);
}

.settings-tab-btn.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
    background: var(--bg-surface);
}

.settings-tab-pane {
    display: none;
    animation: settingsFadeIn 0.15s ease-out;
}

.settings-tab-pane.active {
    display: block;
}

@keyframes settingsFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Store Profile Layout */
.profile-card {
    max-width: 820px;
}

.form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (max-width: 640px) {
    .form-grid-2 {
        grid-template-columns: 1fr;
    }
}

.form-help {
    font-size: 11.5px;
    color: var(--text-muted);
    margin-top: 4px;
}

.badge-module {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    display: inline-block;
}

.badge-action {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    display: inline-block;
}

.badge-action-create { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.badge-action-update { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.badge-action-delete { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.badge-action-auth   { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.badge-action-other  { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

/* Reminder Type Badges */
.badge-type-delivery {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}
.badge-type-dispatch {
    background: #dbeafe;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}
.badge-type-stock {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}
.badge-type-cod {
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
}
.badge-type-expense {
    background: #f3e8ff;
    color: #6b21a8;
    border: 1px solid #e9d5ff;
}
.badge-type-custom {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
}

/* Diff Inspection Modal */
.diff-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 14px;
}

@media (max-width: 768px) {
    .diff-container {
        grid-template-columns: 1fr;
    }
}

.diff-box {
    background: #f8fafc;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 12px;
}

.diff-box-header {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
}

.diff-box pre {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 11.5px;
    line-height: 1.45;
    margin: 0;
    white-space: pre-wrap;
    word-break: break-all;
    max-height: 320px;
    overflow-y: auto;
    color: var(--text-main);
}

.diff-empty {
    font-size: 12px;
    color: var(--text-muted);
    font-style: italic;
    padding: 12px 0;
    text-align: center;
}

/* Toggle Switch */
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 36px;
    height: 20px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #cbd5e1;
    transition: .2s;
    border-radius: 20px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 14px;
    width: 14px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .2s;
    border-radius: 50%;
}

input:checked + .toggle-slider {
    background-color: var(--color-success);
}

input:checked + .toggle-slider:before {
    transform: translateX(16px);
}

.due-indicator {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
    margin-left: 6px;
}
</style>

<!-- Multi-Tab Navigation Bar -->
<div class="settings-tabs-nav" role="tablist" aria-label="Settings Sections" style="margin-top: 8px;">
    <button type="button" class="settings-tab-btn active" id="tabBtnProfile" role="tab" aria-selected="true" aria-controls="paneProfile" onclick="switchTab('profile')">
        <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Store Profile &amp; Defaults
    </button>
    <button type="button" class="settings-tab-btn" id="tabBtnAudit" role="tab" aria-selected="false" aria-controls="paneAudit" onclick="switchTab('audit')">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Activity Audit Logs
    </button>
    <button type="button" class="settings-tab-btn" id="tabBtnReminders" role="tab" aria-selected="false" aria-controls="paneReminders" onclick="switchTab('reminders')">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Reminder Scheduler
    </button>
</div>

<!-- ========================================================================
     TAB 1: STORE PROFILE & DEFAULTS
     ======================================================================== -->
<section class="settings-tab-pane active" id="paneProfile" role="tabpanel" aria-labelledby="tabBtnProfile">
    <div class="card profile-card">
        <div class="card-header">
            <div>
                <div class="card-title">Store Identity &amp; System Defaults</div>
                <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">
                    Values configured here serve as system defaults for low stock alerts, invoice headers, and currency formatters.
                </div>
            </div>
            <div id="lastUpdatedBadge" style="font-size:12px; color:var(--text-muted);">
                <?php if ($lastUpdatedTimestamp): ?>
                    Last updated: <strong><?= date('M j, Y g:i A', strtotime($lastUpdatedTimestamp)) ?></strong>
                <?php else: ?>
                    Using default configuration
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$canManage): ?>
            <div class="alert alert-info" style="margin-bottom: 20px;">
                <svg viewBox="0 0 24 24" width="18" height="18" style="stroke:currentColor;fill:none;stroke-width:2;margin-top:1px;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <div>
                    <strong>View-Only Privilege:</strong> You can inspect current store parameters, but modifying settings requires the <code>settings_manage</code> administrator permission.
                </div>
            </div>
        <?php endif; ?>

        <form id="storeSettingsForm" onsubmit="saveStoreSettings(event)">
            <div class="form-group">
                <label class="form-label" for="settingStoreName">Store Name <span style="color:var(--color-danger)">*</span></label>
                <input type="text" id="settingStoreName" name="store_name" class="form-input" 
                       value="<?= htmlspecialchars($storeSettings['store_name'], ENT_QUOTES, 'UTF-8') ?>" 
                       required minlength="2" maxlength="100" <?= !$canManage ? 'disabled' : '' ?>>
                <div class="form-help">Displayed on customer receipts, invoices, and system notification emails.</div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="settingContactEmail">Contact Email Address <span style="color:var(--color-danger)">*</span></label>
                    <input type="email" id="settingContactEmail" name="contact_email" class="form-input" 
                           value="<?= htmlspecialchars($storeSettings['contact_email'], ENT_QUOTES, 'UTF-8') ?>" 
                           required <?= !$canManage ? 'disabled' : '' ?>>
                    <div class="form-help">Official email recipient for inventory &amp; system alerts.</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="settingContactPhone">Contact Phone Number <span style="color:var(--color-danger)">*</span></label>
                    <input type="tel" id="settingContactPhone" name="contact_phone" class="form-input" 
                           value="<?= htmlspecialchars($storeSettings['contact_phone'], ENT_QUOTES, 'UTF-8') ?>" 
                           required minlength="5" maxlength="30" <?= !$canManage ? 'disabled' : '' ?>>
                    <div class="form-help">Hotline / courier support number shown on receipts.</div>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="settingCurrencySymbol">Default Currency Symbol <span style="color:var(--color-danger)">*</span></label>
                    <input type="text" id="settingCurrencySymbol" name="currency_symbol" class="form-input" 
                           value="<?= htmlspecialchars($storeSettings['currency_symbol'], ENT_QUOTES, 'UTF-8') ?>" 
                           required maxlength="5" style="max-width: 140px; font-weight:700;" <?= !$canManage ? 'disabled' : '' ?>>
                    <div class="form-help">Symbol used across sales, POS, and financial ledgers (e.g. ₱, $, €).</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="settingLowStockThreshold">Default Low Stock Alert Threshold <span style="color:var(--color-danger)">*</span></label>
                    <input type="number" id="settingLowStockThreshold" name="default_low_stock_alert" class="form-input" 
                           value="<?= htmlspecialchars($storeSettings['default_low_stock_alert'], ENT_QUOTES, 'UTF-8') ?>" 
                           required min="0" max="10000" style="max-width: 180px;" <?= !$canManage ? 'disabled' : '' ?>>
                    <div class="form-help">Trigger automatic low stock topbar alerts when product quantity falls to or below this level.</div>
                </div>
            </div>

            <div style="margin-top: 24px; padding-top: 18px; border-top: 1px dashed var(--border-color);">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom: 12px;">
                    <span style="font-size: 20px;">🪙</span>
                    <strong style="font-size: 14px; color: var(--text-color);">Prestige Coins Wallet Settings</strong>
                    <span class="badge badge-success" style="font-size: 11px;">Active</span>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="settingCoinsName">Coins Display Name</label>
                        <input type="text" id="settingCoinsName" name="coins_name" class="form-input"
                               value="<?= htmlspecialchars($storeSettings['coins_name'] ?? 'Prestige Coins', ENT_QUOTES, 'UTF-8') ?>"
                               maxlength="50" <?= !$canManage ? 'disabled' : '' ?>>
                        <div class="form-help">Customer-facing currency name (e.g. Prestige Coins, Apple Store Credits).</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="settingCoinsConversion">Coin to PHP Rate (1 Coin = ₱)</label>
                        <input type="text" id="settingCoinsConversion" name="coins_conversion_rate" class="form-input"
                               value="<?= htmlspecialchars($storeSettings['coins_conversion_rate'] ?? '1.00', ENT_QUOTES, 'UTF-8') ?>"
                               style="max-width: 140px; font-weight:700;" <?= !$canManage ? 'disabled' : '' ?>>
                        <div class="form-help">Fixed 1.00 ratio ensures 1 Prestige Coin equals ₱1.00 PHP purchasing power.</div>
                    </div>
                </div>
            </div>

            <?php if ($canManage): ?>
                <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-color); display:flex; justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary" id="saveSettingsBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" style="stroke:currentColor;fill:none;stroke-width:2;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <span>Save Store Profile</span>
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</section>

<!-- ========================================================================
     TAB 2: ACTIVITY AUDIT LOGS
     ======================================================================== -->
<section class="settings-tab-pane" id="paneAudit" role="tabpanel" aria-labelledby="tabBtnAudit">
    <!-- Filter Toolbar -->
    <div class="filter-bar">
        <div class="filter-group" style="min-width: 220px; flex: 2;">
            <input type="text" id="auditSearchInput" class="form-input" placeholder="Search by operator, module, action, or IP..." onkeyup="handleAuditSearchKey(event)">
        </div>

        <div class="filter-group" style="min-width: 150px;">
            <select id="auditModuleSelect" class="form-select" onchange="fetchAuditLogs(1)">
                <option value="">All Modules</option>
                <option value="auth">auth</option>
                <option value="products">products</option>
                <option value="orders">orders</option>
                <option value="inventory">inventory</option>
                <option value="expenses">expenses</option>
                <option value="settings">settings</option>
                <option value="reminders">reminders</option>
            </select>
        </div>

        <div class="filter-group" style="min-width: 140px;">
            <input type="date" id="auditDateFrom" class="form-input" title="From Date" onchange="fetchAuditLogs(1)">
        </div>

        <div class="filter-group" style="min-width: 140px;">
            <input type="date" id="auditDateTo" class="form-input" title="To Date" onchange="fetchAuditLogs(1)">
        </div>

        <div style="display:flex; gap:8px;">
            <button type="button" class="btn btn-primary btn-sm" onclick="fetchAuditLogs(1)" title="Apply Filters">
                Apply
            </button>
            <button type="button" class="btn btn-outline btn-sm" onclick="resetAuditFilters()" title="Reset Filters">
                Reset
            </button>
        </div>
    </div>

    <!-- Audit Data Table -->
    <div class="table-responsive">
        <table class="table" id="auditLogsTable">
            <thead>
                <tr>
                    <th style="width: 150px;">Timestamp</th>
                    <th style="width: 160px;">Operator</th>
                    <th style="width: 120px;">Module</th>
                    <th style="width: 130px;">Action</th>
                    <th style="width: 90px; text-align:center;">Record ID</th>
                    <th style="width: 120px;">IP Address</th>
                    <th style="width: 110px; text-align: right;">Mutation</th>
                </tr>
            </thead>
            <tbody id="auditTableBody">
                <tr>
                    <td colspan="7" class="text-center" style="padding: 30px; color: var(--text-muted);">
                        Loading audit activities...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination Bar -->
    <div class="pagination-bar" id="auditPaginationBar" style="display:none;">
        <div id="auditPaginationInfo">Page 1 of 1 (0 total entries)</div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn btn-outline btn-sm" id="auditPrevBtn" onclick="changeAuditPage(-1)" disabled>
                &larr; Previous
            </button>
            <button type="button" class="btn btn-outline btn-sm" id="auditNextBtn" onclick="changeAuditPage(1)" disabled>
                Next &rarr;
            </button>
        </div>
    </div>
</section>

<!-- ========================================================================
     TAB 3: OPERATIONAL REMINDER SCHEDULER
     ======================================================================== -->
<section class="settings-tab-pane" id="paneReminders" role="tabpanel" aria-labelledby="tabBtnReminders">
    <!-- Reminder Summary KPIs -->
    <div class="stats-grid">
        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Total Reminders</span>
                <div class="stat-icon icon-primary">📋</div>
            </div>
            <div class="stat-value tabular-nums" id="kpiTotalReminders"><?= $remindersSummary['total'] ?></div>
            <div class="stat-subtext">Configured automated checks</div>
        </div>

        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Active Monitoring</span>
                <div class="stat-icon icon-success">🔔</div>
            </div>
            <div class="stat-value tabular-nums" id="kpiActiveReminders" style="color:var(--color-success);"><?= $remindersSummary['active'] ?></div>
            <div class="stat-subtext">Actively scheduled triggers</div>
        </div>

        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Due / Overdue</span>
                <div class="stat-icon icon-danger">⚠️</div>
            </div>
            <div class="stat-value tabular-nums" id="kpiDueReminders" style="color:var(--color-danger);"><?= $remindersSummary['due'] ?></div>
            <div class="stat-subtext">Awaiting operational scan</div>
        </div>

        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Completed Cycles</span>
                <div class="stat-icon icon-info">✅</div>
            </div>
            <div class="stat-value tabular-nums" id="kpiCompletedReminders"><?= $remindersSummary['completed'] ?></div>
            <div class="stat-subtext">Historical runs concluded</div>
        </div>
    </div>

    <!-- Action & Filter Bar -->
    <div class="filter-bar" style="justify-content: space-between;">
        <div style="display:flex; flex-wrap:wrap; gap:12px; flex:1; align-items:center;">
            <div class="filter-group" style="max-width: 280px;">
                <input type="text" id="remindersSearchInput" class="form-input" placeholder="Search reminders..." onkeyup="handleRemindersSearchKey(event)">
            </div>

            <div class="filter-group" style="max-width: 220px;">
                <select id="remindersTypeSelect" class="form-select" onchange="fetchReminders()">
                    <option value="all">All Types</option>
                    <option value="INCOMING_DELIVERY">🚚 Incoming Delivery Item</option>
                    <option value="ORDER_DISPATCH">📦 Customer Order Delivery</option>
                    <option value="LOW_STOCK_CHECK">⚠️ Low Stock Check</option>
                    <option value="COD_FOLLOWUP">💰 COD Collection Follow-up</option>
                    <option value="EXPENSE_DUE">📉 Expense Cycle Due</option>
                    <option value="CUSTOM">📝 Custom Note</option>
                </select>
            </div>

            <div class="filter-group" style="max-width: 170px;">
                <select id="remindersStatusSelect" class="form-select" onchange="fetchReminders()">
                    <option value="all">All Statuses</option>
                    <option value="active">Active Monitoring</option>
                    <option value="completed">Completed</option>
                    <option value="inactive">Paused / Inactive</option>
                </select>
            </div>
        </div>

        <div>
            <?php if ($canManage): ?>
                <button type="button" class="btn btn-primary" onclick="openScheduleModal()">
                    <svg viewBox="0 0 24 24" width="16" height="16" style="stroke:currentColor;fill:none;stroke-width:2.2;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span>Schedule Reminder</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reminders Table -->
    <div class="table-responsive">
        <table class="table" id="remindersTable">
            <thead>
                <tr>
                    <th style="width: 160px;">Type</th>
                    <th>Title &amp; Operational Notes</th>
                    <th style="width: 180px;">Target Date / Schedule</th>
                    <th style="width: 100px; text-align: center;">Active</th>
                    <th style="width: 160px;">Last Run</th>
                    <th style="width: 170px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody id="remindersTableBody">
                <tr>
                    <td colspan="6" class="text-center" style="padding: 30px; color: var(--text-muted);">
                        Loading operational reminders...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<!-- ========================================================================
     MODAL 1: INSPECT AUDIT DIFF MODAL (Humanized Field Comparison)
     ======================================================================== -->
<div class="modal-overlay" id="inspectDiffModal">
    <div class="modal-card" style="max-width: 780px;">
        <div class="modal-header" style="padding: 20px 24px 18px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #ffffff;">
            <h3 class="modal-title" style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0; letter-spacing: -0.01em;">Audit Log Inspection</h3>
            <button type="button" class="modal-close" onclick="closeInspectDiffModal()" aria-label="Close" title="Close">&times;</button>
        </div>
        <div class="modal-body" style="background: #f8fafc; padding: 22px 24px; overflow-y: auto; max-height: calc(90vh - 140px);">
            <!-- Top Summary Card (Humanized action, operator, when, log entry) -->
            <div id="auditSummaryCard" style="background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <!-- Populated dynamically by inspectAuditLog() -->
            </div>

            <!-- Helpful Guidance Notice Banner -->
            <div style="background: #eff6ff; border: 1px solid #dbeafe; border-radius: 12px; padding: 12px 18px; margin: 16px 0; font-size: 13px; color: #1e40af; line-height: 1.45;">
                Only the fields that changed are shown. “Not set” means the field had no value before this change.
            </div>

            <!-- WHAT CHANGED Section Header -->
            <div style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; margin-bottom: 12px;">
                WHAT CHANGED
            </div>

            <!-- Field Comparison Cards Container -->
            <div id="diffCardsContainer">
                <!-- Dynamically rendered field comparison cards -->
            </div>

            <!-- Footnote description -->
            <div style="font-size: 12px; color: #64748b; margin: 16px 0 12px; line-height: 1.45;">
                In the live system the Before and After values come from the saved old_values and new_values of each log entry.
            </div>
        </div>
        <div class="modal-footer" style="padding: 14px 24px; background: #ffffff; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
            <button type="button" class="btn btn-outline" onclick="closeInspectDiffModal()" style="border-radius: 10px; padding: 8px 24px; font-weight: 600; color: #334155; border-color: #cbd5e1; background: #ffffff;">Close</button>
        </div>
    </div>
</div>

<!-- ========================================================================
     MODAL 2: SCHEDULE OPERATIONAL REMINDER MODAL
     ======================================================================== -->
<div class="modal-overlay" id="scheduleModal">
    <div class="modal-card" style="max-width: 560px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Schedule Operational Reminder</div>
                <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">Schedule incoming delivery items, customer dispatches, or inventory scans</div>
            </div>
            <button type="button" class="modal-close" onclick="closeScheduleModal()" aria-label="Close modal">&times;</button>
        </div>
        <form id="scheduleReminderForm" onsubmit="submitReminderSchedule(event)">
            <div class="modal-body">
                <!-- Quick Preset Chips -->
                <div style="margin-bottom: 14px;">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 6px;">Quick Templates:</div>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11.5px; padding: 4px 10px;" onclick="applyReminderPreset('INCOMING_DELIVERY')">
                            🚚 Incoming Delivery Item
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11.5px; padding: 4px 10px;" onclick="applyReminderPreset('ORDER_DISPATCH')">
                            📦 Customer Order Delivery
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11.5px; padding: 4px 10px;" onclick="applyReminderPreset('LOW_STOCK_CHECK')">
                            ⚠️ Low Stock Scan
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11.5px; padding: 4px 10px;" onclick="applyReminderPreset('COD_FOLLOWUP')">
                            💰 COD Cash Follow-up
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reminderType">Reminder Type <span style="color:var(--color-danger)">*</span></label>
                    <select id="reminderType" name="type" class="form-select" required>
                        <option value="INCOMING_DELIVERY">🚚 Incoming Delivery Item / Supplier Stock Arrival</option>
                        <option value="ORDER_DISPATCH">📦 Customer Order Delivery / Dispatch Follow-up</option>
                        <option value="LOW_STOCK_CHECK">⚠️ Low Stock Threshold Scan</option>
                        <option value="COD_FOLLOWUP">💰 COD Pending Cash Collection Audit</option>
                        <option value="EXPENSE_DUE">📉 Expense Operational Cycle Review</option>
                        <option value="CUSTOM">📝 Custom Reminder / Operational Note</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reminderTitle">Delivery Item / Reminder Title <span style="color:var(--color-danger)">*</span></label>
                    <input type="text" id="reminderTitle" name="title" class="form-input" 
                           placeholder="e.g. Incoming Delivery: iPhone 16 Pro Max 256GB (15 units)" required minlength="3" maxlength="150">
                </div>

                <div class="form-group">
                    <label class="form-label" for="reminderTargetDate">Expected Delivery / Target Date &amp; Time <span style="color:var(--color-danger)">*</span></label>
                    <input type="datetime-local" id="reminderTargetDate" name="target_date" class="form-input" required>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="reminderNotes">Delivery Details / Supplier &amp; Courier Notes</label>
                    <textarea id="reminderNotes" name="notes" class="form-textarea" rows="3" 
                              placeholder="e.g. Supplier name, tracking/waybill number, items to inspect upon arrival..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeScheduleModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="scheduleSubmitBtn">Schedule Reminder</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================
     CLIENT-SIDE INTERACTION CONTROLLER
     ======================================================================== -->
<script>
// State Management
const SettingsApp = {
    activeTab: 'profile',
    canManage: <?= json_encode($canManage) ?>,
    audit: {
        page: 1,
        limit: 15,
        totalPages: 1,
        totalRecords: 0,
        cache: []
    },
    reminders: {
        items: []
    }
};

/**
 * Tab Switching Controller
 */
function switchTab(tabKey, updateHash = true) {
    if (!['profile', 'audit', 'reminders'].includes(tabKey)) {
        tabKey = 'profile';
    }

    SettingsApp.activeTab = tabKey;

    // Update Tab Buttons
    document.querySelectorAll('.settings-tab-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.setAttribute('aria-selected', 'false');
    });

    const activeBtn = document.getElementById('tabBtn' + tabKey.charAt(0).toUpperCase() + tabKey.slice(1));
    if (activeBtn) {
        activeBtn.classList.add('active');
        activeBtn.setAttribute('aria-selected', 'true');
    }

    // Update Panes
    document.querySelectorAll('.settings-tab-pane').forEach(pane => {
        pane.classList.remove('active');
    });

    const activePane = document.getElementById('pane' + tabKey.charAt(0).toUpperCase() + tabKey.slice(1));
    if (activePane) {
        activePane.classList.add('active');
    }

    // Trigger tab-specific dynamic fetches
    if (tabKey === 'audit' && SettingsApp.audit.cache.length === 0) {
        fetchAuditLogs(1);
    } else if (tabKey === 'reminders' && SettingsApp.reminders.items.length === 0) {
        fetchReminders();
    }

    if (updateHash) {
        history.replaceState(null, '', '#' + tabKey);
    }
}

/**
 * 1. TAB 1: Store Profile Submission
 */
async function saveStoreSettings(e) {
    e.preventDefault();
    if (!SettingsApp.canManage) return;

    const btn = document.getElementById('saveSettingsBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span>Saving...</span>';

    const payload = {
        action: 'update_settings',
        store_name: document.getElementById('settingStoreName').value.trim(),
        contact_email: document.getElementById('settingContactEmail').value.trim(),
        contact_phone: document.getElementById('settingContactPhone').value.trim(),
        currency_symbol: document.getElementById('settingCurrencySymbol').value.trim(),
        default_low_stock_alert: document.getElementById('settingLowStockThreshold').value.trim(),
        coins_name: document.getElementById('settingCoinsName') ? document.getElementById('settingCoinsName').value.trim() : 'Prestige Coins',
        coins_conversion_rate: document.getElementById('settingCoinsConversion') ? document.getElementById('settingCoinsConversion').value.trim() : '1.00',
        coins_enabled: '1'
    };

    try {
        const res = await API.post('handler.php', payload);
        if (res && res.success) {
            API.toast('Store settings updated successfully!', 'success');
            const nowStr = new Date().toLocaleString('en-US', {
                month: 'short', day: 'numeric', year: 'numeric',
                hour: 'numeric', minute: '2-digit', hour12: true
            });
            const badge = document.getElementById('lastUpdatedBadge');
            if (badge) {
                badge.innerHTML = 'Last updated: <strong>' + nowStr + '</strong>';
            }

            // Immediately reflect new store name across upper sidebar and document title
            const updatedStoreName = (res.data && res.data.store_name) ? res.data.store_name : payload.store_name;
            if (updatedStoreName) {
                const brandText = document.querySelector('.brand-text');
                if (brandText) {
                    brandText.textContent = updatedStoreName;
                }
                const brandLogo = document.querySelector('.brand-logo');
                if (brandLogo) {
                    brandLogo.setAttribute('alt', updatedStoreName + ' Logo');
                }
                const storefrontLink = document.querySelector('a[href*="/store/"]');
                if (storefrontLink) {
                    storefrontLink.setAttribute('title', 'Open Public ' + updatedStoreName + ' Storefront');
                }
                if (document.title.includes('—')) {
                    const prefix = document.title.split('—')[0].trim();
                    document.title = prefix + ' — ' + updatedStoreName;
                } else {
                    document.title = updatedStoreName;
                }
            }
        } else {
            const errorMsg = (res && res.message) ? res.message : 'Failed to update store settings.';
            API.toast(errorMsg, 'error');
        }
    } catch (err) {
        API.toast('A network error occurred while updating settings.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

/**
 * 2. TAB 2: Audit Logs Fetch & Render
 */
function handleAuditSearchKey(e) {
    if (e.key === 'Enter') {
        fetchAuditLogs(1);
    }
}

function resetAuditFilters() {
    document.getElementById('auditSearchInput').value = '';
    document.getElementById('auditModuleSelect').value = '';
    document.getElementById('auditDateFrom').value = '';
    document.getElementById('auditDateTo').value = '';
    fetchAuditLogs(1);
}

async function fetchAuditLogs(page = 1) {
    SettingsApp.audit.page = page;
    const tbody = document.getElementById('auditTableBody');
    tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="padding: 24px; color: var(--text-muted);">Loading audit activities...</td></tr>';

    const params = new URLSearchParams({
        action: 'list_audit_logs',
        page: page,
        limit: SettingsApp.audit.limit,
        search: document.getElementById('auditSearchInput').value.trim(),
        module: document.getElementById('auditModuleSelect').value.trim(),
        date_from: document.getElementById('auditDateFrom').value,
        date_to: document.getElementById('auditDateTo').value
    });

    try {
        const res = await API.get('handler.php?' + params.toString());
        if (res && res.success && res.data) {
            SettingsApp.audit.cache = res.data.items || [];
            SettingsApp.audit.totalPages = res.data.pagination.total_pages || 1;
            SettingsApp.audit.totalRecords = res.data.pagination.total_records || 0;
            renderAuditLogsTable();
            renderAuditPagination();
        } else {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="padding: 24px; color: var(--color-danger);">Unable to retrieve audit logs.</td></tr>';
        }
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="padding: 24px; color: var(--color-danger);">Error connecting to audit service.</td></tr>';
    }
}

function renderAuditLogsTable() {
    const tbody = document.getElementById('auditTableBody');
    const items = SettingsApp.audit.cache;

    if (!items || items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="padding: 32px; color: var(--text-muted);">No audit log events matched the specified criteria.</td></tr>';
        return;
    }

    let html = '';
    items.forEach(log => {
        const hasDiff = (log.old_values !== null || log.new_values !== null);
        const actionUpper = (log.action || '').toUpperCase();
        let actionBadgeCls = 'badge-action-other';
        if (actionUpper.includes('CREATE') || actionUpper.includes('INSERT')) {
            actionBadgeCls = 'badge-action-create';
        } else if (actionUpper.includes('UPDATE') || actionUpper.includes('TOGGLE')) {
            actionBadgeCls = 'badge-action-update';
        } else if (actionUpper.includes('DELETE') || actionUpper.includes('CANCEL')) {
            actionBadgeCls = 'badge-action-delete';
        } else if (actionUpper.includes('LOGIN') || actionUpper.includes('AUTH')) {
            actionBadgeCls = 'badge-action-auth';
        }

        const dateStr = formatDateTime(log.created_at);
        const recordStr = log.record_id ? '#' + log.record_id : '—';
        const actorName = escapeHtml(log.user_full_name || 'System');
        const username = escapeHtml(log.username || 'system');
        const ipStr = escapeHtml(log.ip_address || '—');
        const moduleStr = escapeHtml(log.module || 'system');

        html += `
            <tr>
                <td style="font-size:12px; color:var(--text-muted);">${dateStr}</td>
                <td>
                    <div style="font-weight:600; color:var(--text-main); font-size:13px;">${actorName}</div>
                    <div style="font-size:11px; color:var(--text-muted);">@${username}</div>
                </td>
                <td><span class="badge-module">${moduleStr}</span></td>
                <td><span class="badge-action ${actionBadgeCls}">${escapeHtml(log.action)}</span></td>
                <td style="text-align:center; font-size:12px; font-weight:600;">${recordStr}</td>
                <td style="font-size:11.5px; color:var(--text-muted); font-family:monospace;">${ipStr}</td>
                <td style="text-align: right;">
                    ${hasDiff ? `
                        <button type="button" class="btn btn-outline btn-sm" onclick="inspectDiff(${log.id})">
                            Inspect Diff
                        </button>
                    ` : `<span style="font-size:11px; color:var(--text-muted); font-style:italic;">No Diff</span>`}
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

function renderAuditPagination() {
    const bar = document.getElementById('auditPaginationBar');
    const info = document.getElementById('auditPaginationInfo');
    const prevBtn = document.getElementById('auditPrevBtn');
    const nextBtn = document.getElementById('auditNextBtn');

    if (SettingsApp.audit.totalRecords === 0) {
        bar.style.display = 'none';
        return;
    }

    bar.style.display = 'flex';
    info.textContent = `Page ${SettingsApp.audit.page} of ${SettingsApp.audit.totalPages} (${SettingsApp.audit.totalRecords} total records)`;
    prevBtn.disabled = SettingsApp.audit.page <= 1;
    nextBtn.disabled = SettingsApp.audit.page >= SettingsApp.audit.totalPages;
}

function changeAuditPage(delta) {
    const newPage = SettingsApp.audit.page + delta;
    if (newPage >= 1 && newPage <= SettingsApp.audit.totalPages) {
        fetchAuditLogs(newPage);
    }
}

/**
 * Dictionary of Human-Friendly Field Labels for Audit Inspector
 */
const AUDIT_FIELD_LABELS = {
    // Stock & Inventory
    stock_qty: 'Stock quantity',
    current_stock: 'Stock quantity',
    quantity: 'Quantity',
    threshold: 'Low-stock threshold',
    low_stock_threshold: 'Low-stock threshold',
    movement_type: 'Movement type',
    quantity_change: 'Quantity change',
    stock_before: 'Stock before',
    stock_after: 'Stock after',
    note: 'Adjustment note',
    notes: 'Adjustment note',
    reason: 'Reason for adjustment',

    // Orders & COD
    order_number: 'Order Number',
    customer_name: 'Customer Name',
    customer_phone: 'Customer Phone',
    delivery_address: 'Delivery Address',
    delivery_notes: 'Delivery Notes',
    courier_name: 'Courier Name',
    tracking_number: 'Tracking Number',
    shipping_status: 'Shipping Status',
    subtotal: 'Items Subtotal',
    discount_amount: 'Discount Amount',
    delivery_fee: 'Delivery Fee',
    total_amount: 'Total Amount (Due)',
    order_status: 'Order Status',
    payment_method: 'Payment Method',
    payment_status: 'COD Payment Status',
    dispatched_at: 'Dispatched Date/Time',
    delivered_at: 'Delivered Date/Time',
    cancelled_at: 'Cancelled Date/Time',
    cancellation_reason: 'Cancellation Reason',
    remitted_at: 'Remitted Date/Time',
    remitted_to_user_id: 'Remitted Receiver Staff ID',

    // Settings
    store_name: 'Store Name',
    contact_email: 'Contact Email',
    contact_phone: 'Contact Phone',
    currency_symbol: 'Currency Symbol',
    default_low_stock_alert: 'Default Low Stock Alert',
    coins_name: 'Coins Display Name',
    coins_symbol: 'Coins Symbol',
    coins_conversion_rate: 'Coin to PHP Rate',
    coins_enabled: 'Prestige Coins Status',

    // Expenses
    title: 'Expense Title',
    category_id: 'Expense Category',
    category_name: 'Category Name',
    amount: 'Expense Amount',
    expense_date: 'Expense Date',
    receipt_path: 'Receipt Attachment',
    void_reason: 'Reason for Voiding',

    // Products & Stock
    name: 'Product Name',
    sku: 'SKU Code',
    category_id: 'Product Category',
    cost_price: 'Cost Price',
    selling_price: 'Selling Price',
    status: 'Catalog Status',
    primary_image: 'Primary Device Photo',
    image_path: 'Device Photo Path',
    product_image: 'Device Photo',
    uploaded_images: 'Uploaded Device Photos',
    storage: 'Storage Capacity',
    color: 'Color / Finish',
    condition_type: 'Device Condition',
    warranty: 'Warranty Coverage',
    specs: 'Hardware Specifications',
    description: 'Product Description',

    // Users & Auth
    username: 'Username',
    full_name: 'Staff Full Name',
    role_id: 'Role ID',
    role_name: 'Role Name',
    email: 'Email Address',
    password: 'Password Hash',
    last_login: 'Last Login',

    // System
    id: 'Record ID',
    user_id: 'Operator User ID',
    created_at: 'Created Timestamp',
    updated_at: 'Updated Timestamp',
    deleted_at: 'Voided / Deleted Timestamp'
};

function getHumanFieldLabel(key) {
    if (AUDIT_FIELD_LABELS[key]) return AUDIT_FIELD_LABELS[key];
    return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

function formatInspectVal(val, key = '') {
    if (val === null || val === undefined || val === '') {
        return '<em style="color: #64748b; font-style: italic;">Not set</em>';
    }
    if (typeof val === 'boolean') {
        return `<span style="font-family: monospace; font-weight: 700;">${val ? 'true' : 'false'}</span>`;
    }

    const k = (key || '').toLowerCase();

    // Array formatting (special support for image lists)
    if (Array.isArray(val)) {
        if (val.length === 0) return '<em style="color: #64748b; font-style: italic;">None</em>';
        const isImagesList = val.some(item => typeof item === 'string' && (item.includes('uploads/') || /\.(png|jpe?g|webp)$/i.test(item)));
        if (isImagesList) {
            return `
                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px;">
                    ${val.map(imgPath => {
                        const cleanPath = String(imgPath).startsWith('/') ? String(imgPath).substring(1) : String(imgPath);
                        return `
                            <div style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 3px; background: #ffffff; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <img src="../../${escapeHtml(cleanPath)}" alt="Uploaded Photo" style="width: 44px; height: 44px; object-fit: contain; display: block;" onerror="this.outerHTML='<span style=\\'font-size:20px;\\'>📱</span>';">
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        }
        return `<pre style="margin: 0; font-family: monospace; font-size: 11.5px; white-space: pre-wrap; word-break: break-all;">${escapeHtml(JSON.stringify(val, null, 2))}</pre>`;
    }

    if (typeof val === 'object') {
        return `<pre style="margin: 0; font-family: monospace; font-size: 11.5px; white-space: pre-wrap; word-break: break-all;">${escapeHtml(JSON.stringify(val, null, 2))}</pre>`;
    }

    const str = String(val);

    // Image & Photo Thumbnail Rendering
    const isImgKey = k.includes('image') || k.includes('photo') || k.includes('receipt');
    const isImgPath = /\.(png|jpe?g|webp|gif|svg)$/i.test(str) || str.includes('uploads/');
    if (isImgKey || isImgPath) {
        const cleanPath = str.startsWith('/') ? str.substring(1) : str;
        const fullUrl = '../../' + escapeHtml(cleanPath);
        return `
            <div style="display: flex; align-items: center; gap: 10px;">
                <img src="${fullUrl}" alt="Device Photo" style="width: 48px; height: 48px; object-fit: contain; border-radius: 8px; border: 1px solid #cbd5e1; background: #ffffff; padding: 2px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);" onerror="this.outerHTML='<span style=\\'font-size: 22px; margin-right: 4px;\\'>📱</span>';">
                <span style="font-family: monospace; font-size: 11.5px; word-break: break-all; color: #334155;">${escapeHtml(str)}</span>
            </div>
        `;
    }

    if ((k.includes('price') || k.includes('amount') || k.includes('fee') || k.includes('subtotal') || k.includes('cost')) && !isNaN(Number(val)) && str.trim() !== '') {
        return `₱${Number(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
    return escapeHtml(str);
}

function renderChangeCard(key, oldVal, newVal) {
    const humanLabel = getHumanFieldLabel(key);
    const hasOld = (oldVal !== undefined && oldVal !== null && oldVal !== '');
    const hasNew = (newVal !== undefined && newVal !== null && newVal !== '');

    let badgeText = 'CHANGED';
    let badgeStyle = 'background: #fef3c7; color: #b45309; border: 1px solid #fde68a;';
    let beforeBg = '#fef2f2';
    let beforeLabelColor = '#991b1b';
    let beforeValStyle = 'color: #991b1b; font-weight: 700;';
    let afterBg = '#f0fdf4';
    let afterLabelColor = '#166534';
    let afterValStyle = 'color: #166534; font-weight: 700;';

    if (!hasOld && hasNew) {
        badgeText = 'NEW';
        badgeStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;';
        beforeBg = '#f1f5f9';
        beforeLabelColor = '#94a3b8';
        beforeValStyle = 'color: #64748b; font-style: italic;';
    } else if (hasOld && !hasNew) {
        badgeText = 'REMOVED';
        badgeStyle = 'background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;';
        afterBg = '#f1f5f9';
        afterLabelColor = '#94a3b8';
        afterValStyle = 'color: #64748b; font-style: italic;';
    }

    const beforeHtml = formatInspectVal(oldVal, key);
    const afterHtml = formatInspectVal(newVal, key);

    return `
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 18px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <div style="display: flex; align-items: baseline; gap: 8px;">
                <strong style="font-size: 14.5px; font-weight: 700; color: #0f172a;">${escapeHtml(humanLabel)}</strong>
                <code style="font-family: monospace; font-size: 12px; color: #64748b; background: #f8fafc; padding: 1px 6px; border-radius: 4px; border: 1px solid #f1f5f9;">${escapeHtml(key)}</code>
            </div>
            <span style="font-size: 11px; font-weight: 700; letter-spacing: 0.5px; padding: 3px 9px; border-radius: 6px; text-transform: uppercase; ${badgeStyle}">
                ${badgeText}
            </span>
        </div>
        <div style="display: flex; align-items: center; gap: 14px;">
            <!-- BEFORE -->
            <div style="flex: 1; border-radius: 10px; padding: 10px 14px; background: ${beforeBg}; min-width: 0;">
                <div style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: ${beforeLabelColor}; margin-bottom: 4px;">
                    BEFORE
                </div>
                <div style="font-size: 14px; word-break: break-word; ${beforeValStyle}">
                    ${beforeHtml}
                </div>
            </div>
            <!-- ARROW -->
            <div style="color: #94a3b8; font-size: 18px; font-weight: 600; flex-shrink: 0;">&rarr;</div>
            <!-- AFTER -->
            <div style="flex: 1; border-radius: 10px; padding: 10px 14px; background: ${afterBg}; min-width: 0;">
                <div style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: ${afterLabelColor}; margin-bottom: 4px;">
                    AFTER
                </div>
                <div style="font-size: 14px; word-break: break-word; ${afterValStyle}">
                    ${afterHtml}
                </div>
            </div>
        </div>
    </div>
    `;
}

function getHumanActionInfo(log) {
    const act = (log.action || '').toUpperCase();
    const mod = (log.module || '').toLowerCase();

    let title = `${act} Performed`;
    let badgeText = act;
    let iconBg = '#eff6ff';
    let iconColor = '#2563eb';

    const penSvg = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>`;
    const plusSvg = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`;
    const trashSvg = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>`;

    let iconSvg = penSvg;

    if (mod === 'inventory') {
        if (act.includes('ADJUST') || act === 'UPDATE') {
            title = 'Stock adjusted';
            badgeText = 'ADJUSTMENT';
            iconSvg = penSvg;
            iconBg = '#eff6ff';
            iconColor = '#2563eb';
        } else {
            title = 'Inventory updated';
            badgeText = act;
            iconSvg = penSvg;
        }
    } else if (mod === 'products') {
        if (act.includes('CREATE') || act === 'INSERT') {
            title = 'Product created';
            badgeText = 'CREATED';
            iconSvg = plusSvg;
            iconBg = '#f0fdf4';
            iconColor = '#16a34a';
        } else if (act.includes('SET_PRIMARY') || act.includes('IMAGE') || act.includes('PHOTO')) {
            title = 'Product photo updated';
            badgeText = 'PHOTO';
            iconSvg = penSvg;
            iconBg = '#eff6ff';
            iconColor = '#2563eb';
        } else if (act.includes('UPDATE')) {
            title = 'Product updated';
            badgeText = 'UPDATE';
            iconSvg = penSvg;
            iconBg = '#eff6ff';
            iconColor = '#2563eb';
        } else if (act.includes('DELETE')) {
            title = 'Product deleted';
            badgeText = 'DELETED';
            iconSvg = trashSvg;
            iconBg = '#fef2f2';
            iconColor = '#dc2626';
        }
    } else if (mod === 'orders' || mod === 'sales') {
        if (act === 'INSERT' || act === 'CREATE') {
            title = 'Order created';
            badgeText = 'CREATED';
            iconSvg = plusSvg;
            iconBg = '#f0fdf4';
            iconColor = '#16a34a';
        } else if (act.includes('STATUS')) {
            title = 'Order status changed';
            badgeText = 'STATUS';
            iconSvg = penSvg;
        } else if (act.includes('REMIT')) {
            title = 'COD payment remitted';
            badgeText = 'REMITTANCE';
            iconSvg = penSvg;
            iconBg = '#f0fdf4';
            iconColor = '#16a34a';
        }
    } else if (mod === 'settings') {
        title = 'Store settings updated';
        badgeText = 'SETTINGS';
        iconSvg = penSvg;
        iconBg = '#eff6ff';
        iconColor = '#2563eb';
    } else if (mod === 'reminders') {
        title = act.includes('EXECUTE') ? 'Reminder scan executed' : 'Reminder updated';
        badgeText = 'REMINDER';
        iconSvg = penSvg;
        iconBg = '#fef3c7';
        iconColor = '#b45309';
    }

    return { title, badgeText, iconSvg, iconBg, iconColor };
}

/**
 * Audit Diff Modal Inspection (Matching Reference Design)
 */
function inspectDiff(logId) {
    const log = SettingsApp.audit.cache.find(item => item.id === logId);
    if (!log) return;

    const auditSummaryCard = document.getElementById('auditSummaryCard');
    const diffCardsContainer = document.getElementById('diffCardsContainer');
    const info = getHumanActionInfo(log);
    const recordStr = log.record_id ? `#${log.record_id}` : 'N/A';

    // Render Top Summary Card
    if (auditSummaryCard) {
        auditSummaryCard.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: ${info.iconBg}; display: flex; align-items: center; justify-content: center; color: ${info.iconColor}; flex-shrink: 0;">
                    ${info.iconSvg}
                </div>
                <div>
                    <h4 style="margin: 0 0 3px 0; font-size: 16.5px; font-weight: 700; color: #0f172a; letter-spacing: -0.01em;">${escapeHtml(info.title)}</h4>
                    <div style="font-size: 12.5px; color: #64748b;">
                        Module: ${escapeHtml(log.module)} &middot; Record ${escapeHtml(recordStr)}
                    </div>
                </div>
            </div>
            <div style="font-size: 11px; font-weight: 700; color: #475569; letter-spacing: 0.6px; text-transform: uppercase; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; flex-shrink: 0;">
                ${escapeHtml(info.badgeText)}
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1.2fr 1.5fr 0.8fr; gap: 16px; margin-top: 18px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
            <div>
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">WHEN</div>
                <div style="font-weight: 600; color: #0f172a; font-size: 13.5px;">${escapeHtml(log.created_at || '—')}</div>
            </div>
            <div>
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">OPERATOR</div>
                <div style="font-size: 13.5px;">
                    <strong style="color: #0f172a; font-weight: 600;">${escapeHtml(log.user_full_name || 'System')}</strong> 
                    <span style="color: #64748b; font-size: 12.5px;">@${escapeHtml(log.username || 'user')}</span>
                </div>
            </div>
            <div>
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">LOG ENTRY</div>
                <div style="font-weight: 700; color: #0f172a; font-size: 14px;">#${escapeHtml(log.id)}</div>
            </div>
        </div>
        `;
    }

    // Parse JSON payloads safely
    let oldVal = null;
    let newVal = null;

    try {
        oldVal = (typeof log.old_values === 'string') ? JSON.parse(log.old_values) : log.old_values;
    } catch (e) {
        oldVal = log.old_values;
    }

    try {
        newVal = (typeof log.new_values === 'string') ? JSON.parse(log.new_values) : log.new_values;
    } catch (e) {
        newVal = log.new_values;
    }

    renderDiffCards(log, oldVal, newVal);

    const modal = document.getElementById('inspectDiffModal');
    if (modal) {
        modal.classList.add('active');
    }
}

function renderDiffCards(log, oldVal, newVal) {
    const diffCardsContainer = document.getElementById('diffCardsContainer');
    if (!diffCardsContainer) return;

    // CASE 1: Auth & Session Events (No payload modification)
    if (!oldVal && !newVal) {
        diffCardsContainer.innerHTML = `
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px 20px; text-align: center; color: #64748b; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
            <div style="font-size: 28px; margin-bottom: 8px;">🔐</div>
            <div style="font-weight: 700; color: #0f172a; font-size: 15px; margin-bottom: 4px;">
                ${escapeHtml((log.action || '').replace(/_/g, ' '))} Activity
            </div>
            <div style="font-size: 13px; max-width: 440px; margin: 0 auto; line-height: 1.45;">
                Operator <strong>${escapeHtml(log.user_full_name || 'User')}</strong> performed an action (${escapeHtml(log.action || '')}). No database records were modified.
            </div>
        </div>
        `;
        return;
    }

    // CASE 2: Record Creation (newVal exists, oldVal is null)
    if (!oldVal && newVal) {
        const keys = typeof newVal === 'object' && newVal !== null ? Object.keys(newVal) : [];
        if (keys.length > 0) {
            diffCardsContainer.innerHTML = keys.map(k => renderChangeCard(k, null, newVal[k])).join('');
        } else {
            diffCardsContainer.innerHTML = renderChangeCard('Record', null, newVal);
        }
        return;
    }

    // CASE 3: Record Deletion / Void (oldVal exists, newVal is null)
    if (oldVal && !newVal) {
        const keys = typeof oldVal === 'object' && oldVal !== null ? Object.keys(oldVal) : [];
        if (keys.length > 0) {
            diffCardsContainer.innerHTML = keys.map(k => renderChangeCard(k, oldVal[k], null)).join('');
        } else {
            diffCardsContainer.innerHTML = renderChangeCard('Record', oldVal, null);
        }
        return;
    }

    // CASE 4: Field-by-Field Modification (Both oldVal and newVal exist)
    const oldObj = (typeof oldVal === 'object' && oldVal !== null) ? oldVal : {};
    const newObj = (typeof newVal === 'object' && newVal !== null) ? newVal : {};
    const allKeys = Array.from(new Set([...Object.keys(oldObj), ...Object.keys(newObj)]));
    const changedKeys = allKeys.filter(k => {
        const o = oldObj[k];
        const n = newObj[k];
        return (o === undefined && n !== undefined) || (o !== undefined && n === undefined) || (JSON.stringify(o) !== JSON.stringify(n));
    });

    if (changedKeys.length === 0) {
        diffCardsContainer.innerHTML = `
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; text-align: center; color: #64748b; font-size: 13.5px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
            Payload was submitted with unchanged attribute values.
        </div>
        `;
        return;
    }

    diffCardsContainer.innerHTML = changedKeys.map(k => renderChangeCard(k, oldObj[k], newObj[k])).join('');
}

function closeInspectDiffModal() {
    const modal = document.getElementById('inspectDiffModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

/**
 * 3. TAB 3: Operational Reminders Scheduler
 */
function handleRemindersSearchKey(e) {
    if (e.key === 'Enter') {
        fetchReminders();
    }
}

async function fetchReminders() {
    const tbody = document.getElementById('remindersTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center" style="padding: 24px; color: var(--text-muted);">Loading operational reminders...</td></tr>';

    const params = new URLSearchParams({
        action: 'list_reminders',
        search: document.getElementById('remindersSearchInput').value.trim(),
        type: document.getElementById('remindersTypeSelect').value,
        status: document.getElementById('remindersStatusSelect').value
    });

    try {
        const res = await API.get('handler.php?' + params.toString());
        if (res && res.success && res.data) {
            SettingsApp.reminders.items = res.data.items || [];
            if (res.data.summary) {
                updateReminderKPIs(res.data.summary);
            }
            renderRemindersTable();
        } else {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center" style="padding: 24px; color: var(--color-danger);">Unable to retrieve reminders.</td></tr>';
        }
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center" style="padding: 24px; color: var(--color-danger);">Error connecting to reminder service.</td></tr>';
    }
}

function updateReminderKPIs(summary) {
    document.getElementById('kpiTotalReminders').textContent = summary.total || 0;
    document.getElementById('kpiActiveReminders').textContent = summary.active || 0;
    document.getElementById('kpiDueReminders').textContent = summary.due || 0;
    document.getElementById('kpiCompletedReminders').textContent = summary.completed || 0;
}

function renderRemindersTable() {
    const tbody = document.getElementById('remindersTableBody');
    const items = SettingsApp.reminders.items;

    if (!items || items.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center" style="padding: 36px; color: var(--text-muted);">
                    <div>No operational reminders scheduled.</div>
                    ${SettingsApp.canManage ? `
                        <button type="button" class="btn btn-primary btn-sm" onclick="openScheduleModal()" style="margin-top: 10px;">
                            + Schedule First Reminder
                        </button>
                    ` : ''}
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    items.forEach(rem => {
        let badgeLabel = 'Custom Task';
        let badgeCls = 'badge-type-custom';
        if (rem.type === 'INCOMING_DELIVERY') {
            badgeLabel = '🚚 Incoming Delivery';
            badgeCls = 'badge-type-delivery';
        } else if (rem.type === 'ORDER_DISPATCH') {
            badgeLabel = '📦 Order Delivery';
            badgeCls = 'badge-type-dispatch';
        } else if (rem.type === 'LOW_STOCK_CHECK') {
            badgeLabel = '⚠️ Low Stock Scan';
            badgeCls = 'badge-type-stock';
        } else if (rem.type === 'COD_FOLLOWUP') {
            badgeLabel = '💰 COD Remittance';
            badgeCls = 'badge-type-cod';
        } else if (rem.type === 'EXPENSE_DUE') {
            badgeLabel = '📉 Expense Due';
            badgeCls = 'badge-type-expense';
        }

        const dateStr = formatDateTime(rem.target_date);
        const lastRunStr = rem.last_run_at ? formatDateTime(rem.last_run_at) : 'Never executed';
        const titleStr = escapeHtml(rem.title);
        const notesStr = rem.notes ? escapeHtml(rem.notes) : '';
        const isCompleted = rem.is_completed;
        const isActive = rem.is_active;

        html += `
            <tr id="reminderRow_${rem.id}" style="${isCompleted ? 'opacity: 0.65; background: #fafafa;' : ''}">
                <td><span class="badge ${badgeCls}">${badgeLabel}</span></td>
                <td>
                    <div style="font-weight:600; color:var(--text-main); font-size:13.5px; ${isCompleted ? 'text-decoration:line-through;' : ''}">
                        ${titleStr}
                    </div>
                    ${notesStr ? `<div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">${notesStr}</div>` : ''}\n                </td>
                <td>
                    <div style="font-size:12.5px; font-weight:500;">
                        ${dateStr}
                        ${rem.is_due ? `<span class="due-indicator">DUE NOW</span>` : ''}
                    </div>
                </td>
                <td style="text-align: center;">
                    ${SettingsApp.canManage ? `
                        <label class="toggle-switch" title="Toggle active status">
                            <input type="checkbox" ${isActive ? 'checked' : ''} onchange="toggleReminderActive(${rem.id}, this.checked)">
                            <span class="toggle-slider"></span>
                        </label>
                    ` : `
                        <span class="badge ${isActive ? 'badge-delivered' : 'badge-inactive'}">${isActive ? 'Active' : 'Paused'}</span>
                    `}
                </td>
                <td style="font-size:12px; color:var(--text-muted);" id="reminderLastRun_${rem.id}">
                    ${lastRunStr}
                </td>
                <td style="text-align: right;">
                    <div style="display:inline-flex; gap:6px; align-items:center;">
                        ${SettingsApp.canManage ? `
                            <button type="button" class="btn btn-outline btn-sm" id="btnRun_${rem.id}" onclick="executeReminder(${rem.id})" title="Execute automated scan now">
                                <svg viewBox="0 0 24 24" width="12" height="12" style="fill:currentColor;stroke:none;"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                Run Now
                            </button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="deleteReminder(${rem.id})" title="Delete reminder" style="color:var(--color-danger); border-color:#fecaca;">
                                <svg viewBox="0 0 24 24" width="13" height="13" style="stroke:currentColor;fill:none;stroke-width:2;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            </button>
                        ` : `
                            <span style="font-size:11px; color:var(--text-muted);">View-only</span>
                        `}
                    </div>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

/**
 * Execute Operational Reminder ("Run Now")
 */
async function executeReminder(id) {
    if (!SettingsApp.canManage) return;

    const btn = document.getElementById('btnRun_' + id);
    const originalContent = btn ? btn.innerHTML : 'Run Now';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Scanning...';
    }

    try {
        const res = await API.post('handler.php', {
            action: 'execute_reminder',
            id: id
        });

        if (res && res.success && res.data) {
            API.toast(res.data.summary, 'success');

            // Update row last run
            const lastRunEl = document.getElementById('reminderLastRun_' + id);
            if (lastRunEl) {
                lastRunEl.textContent = formatDateTime(res.data.executed_at);
            }

            // Refresh KPIs & reminder list in background
            fetchReminders();
        } else {
            const msg = (res && res.message) ? res.message : 'Execution failed.';
            API.toast(msg, 'error');
        }
    } catch (err) {
        API.toast('A network error occurred while executing the reminder scan.', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    }
}

/**
 * Toggle Reminder Active State
 */
async function toggleReminderActive(id, isActive) {
    if (!SettingsApp.canManage) return;

    try {
        const res = await API.post('handler.php', {
            action: 'toggle_reminder',
            id: id,
            field: 'is_active',
            is_active: isActive ? 1 : 0
        });

        if (res && res.success) {
            API.toast('Reminder status updated.', 'info');
            fetchReminders();
        } else {
            API.toast('Failed to update status.', 'error');
            fetchReminders();
        }
    } catch (err) {
        API.toast('Network error updating status.', 'error');
        fetchReminders();
    }
}

/**
 * Delete Reminder
 */
async function deleteReminder(id) {
    if (!SettingsApp.canManage) return;
    if (!confirm('Are you sure you want to permanently remove this operational reminder?')) {
        return;
    }

    try {
        const res = await API.post('handler.php', {
            action: 'delete_reminder',
            id: id
        });

        if (res && res.success) {
            API.toast('Reminder removed successfully.', 'info');
            fetchReminders();
        } else {
            API.toast('Unable to remove reminder.', 'error');
        }
    } catch (err) {
        API.toast('Network error removing reminder.', 'error');
    }
}

/**
 * Schedule Reminder Modal Controls
 */
function openScheduleModal() {
    if (!SettingsApp.canManage) return;

    const form = document.getElementById('scheduleReminderForm');
    form.reset();

    // Default target date to tomorrow at 9:00 AM
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(9, 0, 0, 0);

    const pad = (n) => String(n).padStart(2, '0');
    const localIso = `${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}T${pad(tomorrow.getHours())}:${pad(tomorrow.getMinutes())}`;
    
    const dateInput = document.getElementById('reminderTargetDate');
    dateInput.value = localIso;
    dateInput.min = new Date().toISOString().slice(0, 16);

    document.getElementById('scheduleModal').classList.add('active');
}

function closeScheduleModal() {
    document.getElementById('scheduleModal').classList.remove('active');
}

function applyReminderPreset(presetType) {
    const typeSelect = document.getElementById('reminderType');
    const titleInput = document.getElementById('reminderTitle');
    const notesInput = document.getElementById('reminderNotes');

    if (typeSelect) typeSelect.value = presetType;

    if (presetType === 'INCOMING_DELIVERY') {
        titleInput.value = 'Incoming Delivery: iPhone 16 Pro Max 256GB (10 units)';
        notesInput.value = 'Receive supplier shipment, inspect factory seals & IMEI numbers, and update Stock Ledger.';
        titleInput.focus();
    } else if (presetType === 'ORDER_DISPATCH') {
        titleInput.value = 'Customer Order Deliveries: Follow up In-Transit & Scheduled Shipments';
        notesInput.value = 'Verify courier waybill tracking and confirm scheduled customer deliveries.';
        titleInput.focus();
    } else if (presetType === 'LOW_STOCK_CHECK') {
        titleInput.value = 'Low Stock Threshold Catalog Scan';
        notesInput.value = 'Check items at or below minimum stock threshold for immediate reorder.';
        titleInput.focus();
    } else if (presetType === 'COD_FOLLOWUP') {
        titleInput.value = 'COD Pending Cash Collection & Remittance Audit';
        notesInput.value = 'Verify collected courier cash and remit settled COD orders to store drawer.';
        titleInput.focus();
    }
}

async function submitReminderSchedule(e) {
    e.preventDefault();
    if (!SettingsApp.canManage) return;

    const btn = document.getElementById('scheduleSubmitBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Scheduling...';

    const payload = {
        action: 'create_reminder',
        title: document.getElementById('reminderTitle').value.trim(),
        type: document.getElementById('reminderType').value,
        target_date: document.getElementById('reminderTargetDate').value,
        notes: document.getElementById('reminderNotes').value.trim()
    };

    try {
        const res = await API.post('handler.php', payload);
        if (res && res.success) {
            API.toast('Operational reminder scheduled successfully!', 'success');
            closeScheduleModal();
            fetchReminders();
        } else {
            const errorMsg = (res && res.message) ? res.message : 'Validation failed. Please check inputs.';
            API.toast(errorMsg, 'error');
        }
    } catch (err) {
        API.toast('Network error while scheduling reminder.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// Utility Helpers
function formatDateTime(str) {
    if (!str) return '—';
    const d = new Date(str.replace(/-/g, '/'));
    if (isNaN(d.getTime())) return str;
    return d.toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: 'numeric', minute: '2-digit', hour12: true
    });
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    // Check initial hash in URL
    const hash = window.location.hash.replace('#', '');
    if (['profile', 'audit', 'reminders'].includes(hash)) {
        switchTab(hash, false);
    }
});
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
