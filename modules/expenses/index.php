<?php
/**
 * Expenses Ledger, KPI Cards & Receipt Lightview
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

$pageTitle = 'Expenses Ledger';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_permission('expenses_view');

$canManage = has_permission('expenses_manage');
$canDelete = has_permission('expenses_delete'); // Admin only
$db = get_db();

// Pre-load active expense categories for dropdown filters and forms
$stmtCats = $db->query("SELECT id, name FROM expense_categories ORDER BY name ASC");
$categories = $stmtCats->fetchAll();

// Pre-load summary metrics for instant SSR render
$stmtMonth = $db->query("
    SELECT COALESCE(SUM(amount), 0.00) 
    FROM expenses 
    WHERE deleted_at IS NULL 
      AND DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
");
$monthTotal = (float) $stmtMonth->fetchColumn();

$stmtToday = $db->query("
    SELECT COALESCE(SUM(amount), 0.00) 
    FROM expenses 
    WHERE deleted_at IS NULL 
      AND expense_date = CURRENT_DATE
");
$todayTotal = (float) $stmtToday->fetchColumn();

$stmtTop = $db->query("
    SELECT c.name, COALESCE(SUM(e.amount), 0.00) AS total
    FROM expenses e
    JOIN expense_categories c ON e.category_id = c.id
    WHERE e.deleted_at IS NULL 
      AND DATE_FORMAT(e.expense_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
    GROUP BY c.id, c.name
    ORDER BY total DESC
    LIMIT 1
");
$topCat = $stmtTop->fetch();
$topCategoryName = $topCat ? $topCat['name'] : 'None';
$topCategoryTotal = $topCat ? (float) $topCat['total'] : 0.00;

$stmtCount = $db->query("SELECT COUNT(*) FROM expenses WHERE deleted_at IS NULL");
$totalEntries = (int) $stmtCount->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Action Header -->
<div class="page-header" style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 10px;">
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="<?= BASE_URL ?>/modules/expenses/categories.php" class="btn btn-outline">
            🏷️ Categories
        </a>
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-primary" id="openAddExpenseBtn">
                <span>+</span> Record Expense
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Stat KPI Cards Grid -->
<div class="stats-grid">
    <!-- Stat 1: This Month Total Expenses -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">This Month's Expenses</span>
            <div class="stat-icon icon-danger">📉</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiMonthTotal" style="color: var(--color-danger);">
            ₱ <?= number_format($monthTotal, 2) ?>
        </div>
        <div class="stat-subtext">Current month disbursements</div>
    </div>

    <!-- Stat 2: Today's Expenses -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Today's Expenses</span>
            <div class="stat-icon icon-warning">⏱️</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiTodayTotal">
            ₱ <?= number_format($todayTotal, 2) ?>
        </div>
        <div class="stat-subtext">Realized today</div>
    </div>

    <!-- Stat 3: Top Expense Category -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Top Category (Month)</span>
            <div class="stat-icon icon-primary">🏷️</div>
        </div>
        <div class="stat-value" id="kpiTopCategory" style="font-size: 20px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($topCategoryName, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($topCategoryName, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="stat-subtext tabular-nums" id="kpiTopCategoryTotal">
            ₱ <?= number_format($topCategoryTotal, 2) ?> this month
        </div>
    </div>

    <!-- Stat 4: Total Entries -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Total Entries</span>
            <div class="stat-icon icon-success">📋</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiTotalEntries">
            <?= number_format($totalEntries) ?>
        </div>
        <div class="stat-subtext">Active recorded expenses</div>
    </div>
</div>

<!-- Filter Toolbar -->
<div class="filter-bar">
    <div class="filter-group" style="flex: 2; min-width: 200px;">
        <input type="text" class="form-input" id="searchFilter" placeholder="🔍 Search title or notes...">
    </div>

    <div class="filter-group" style="min-width: 170px;">
        <select class="form-select" id="categoryFilter">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group" style="min-width: 140px;">
        <input type="date" class="form-input" id="dateFromFilter" title="From date">
    </div>

    <div class="filter-group" style="min-width: 140px;">
        <input type="date" class="form-input" id="dateToFilter" title="To date">
    </div>

    <div class="filter-group-fixed" style="display: flex; gap: 8px;">
        <button type="button" class="btn btn-primary" id="applyFiltersBtn">
            Filter
        </button>
        <button type="button" class="btn btn-outline" id="resetFiltersBtn" title="Reset all filters">
            Reset
        </button>
    </div>
</div>

<!-- Expense Ledger Table Container -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive" style="border: none; border-radius: 0; box-shadow: none;">
        <table class="table" id="expensesTable">
            <thead>
                <tr>
                    <th style="width: 120px; white-space: nowrap;">Date</th>
                    <th style="min-width: 240px;">Expense Title</th>
                    <th style="width: 180px; min-width: 160px; white-space: nowrap;">Category</th>
                    <th style="width: 150px; min-width: 140px; white-space: nowrap;">Recorded By</th>
                    <th style="width: 130px; text-align: center; white-space: nowrap;">Receipt</th>
                    <th style="width: 140px; text-align: right; white-space: nowrap;">Amount</th>
                    <?php if ($canManage || $canDelete): ?>
                        <th style="width: 160px; text-align: right; white-space: nowrap;">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="expensesTableBody">
                <tr>
                    <td colspan="<?= ($canManage || $canDelete) ? '7' : '6' ?>" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                        <div style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
                        Loading expenses...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination Container -->
    <div class="pagination-container" id="paginationBar">
        <div id="paginationInfo">Showing 0 to 0 of 0 entries</div>
        <div class="pagination-controls">
            <button type="button" class="btn btn-outline btn-sm" id="prevPageBtn" disabled>&laquo; Previous</button>
            <span id="pageIndicator" style="font-size: 13px; font-weight: 500; color: var(--text-main); margin: 0 4px;">Page 1 of 1</span>
            <button type="button" class="btn btn-outline btn-sm" id="nextPageBtn" disabled>Next &raquo;</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Record / Edit Expense Drawer Dialog                                -->
<!-- ========================================================================= -->
<?php if ($canManage): ?>
<div class="modal-overlay" id="addExpenseModal" role="dialog" aria-modal="true" aria-labelledby="expenseModalTitle">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
            <h3 class="modal-title" id="expenseModalTitle">Record Expense</h3>
            <button type="button" class="modal-close" id="closeExpenseModal" aria-label="Close dialog">&times;</button>
        </div>
        <form id="expenseForm" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="id" id="expenseId" value="0">
            <div class="modal-body">
                <div id="expenseModalAlert" style="display: none;" class="alert alert-danger"></div>

                <div class="form-group">
                    <label class="form-label" for="expenseTitle">Expense Title <span style="color: var(--color-danger);">*</span></label>
                    <input type="text" class="form-input" id="expenseTitle" name="title" required maxlength="150" placeholder="e.g. Delivery Rider Fuel Allowance">
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                    <div class="form-group">
                        <label class="form-label" for="expenseCategory">Category <span style="color: var(--color-danger);">*</span></label>
                        <select class="form-select" id="expenseCategory" name="category_id" required>
                            <option value="">Select Category...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="expenseAmount">Amount (₱) <span style="color: var(--color-danger);">*</span></label>
                        <input type="number" class="form-input tabular-nums" id="expenseAmount" name="amount" required step="0.01" min="0.01" placeholder="0.00">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="expenseDate">Expense Date <span style="color: var(--color-danger);">*</span></label>
                    <input type="date" class="form-input" id="expenseDate" name="expense_date" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="expenseReceipt">
                        Receipt Attachment <span style="font-weight: 400; color: var(--text-muted); font-size: 12px;">(JPG, PNG, PDF &le; 5MB)</span>
                    </label>
                    <input type="file" class="form-input" id="expenseReceipt" name="receipt" accept=".jpg,.jpeg,.png,.pdf">
                    <div id="currentReceiptNotice" style="display: none; margin-top: 6px; font-size: 12.5px; color: var(--color-primary);"></div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="expenseNotes">Notes / Remarks</label>
                    <textarea class="form-textarea" id="expenseNotes" name="notes" rows="3" placeholder="Optional supplier info, reference number, OR #, or payment details..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelExpenseBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveExpenseBtn">Save Expense</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL: Receipt Lightview Dialog                                           -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="receiptModal" role="dialog" aria-modal="true" aria-labelledby="receiptModalTitle">
    <div class="modal-card" style="max-width: 680px;">
        <div class="modal-header">
            <h3 class="modal-title" id="receiptModalTitle">Receipt Preview</h3>
            <button type="button" class="modal-close" id="closeReceiptModal" aria-label="Close dialog">&times;</button>
        </div>
        <div class="modal-body" style="text-align: center; padding: 24px;">
            <div id="receiptImageWrapper" style="display: none;">
                <img id="receiptModalImg" src="" alt="Receipt Preview" style="max-width: 100%; max-height: 65vh; border-radius: var(--radius-md); object-fit: contain; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color);">
            </div>
            <div id="receiptPdfWrapper" style="display: none; padding: 30px 10px;">
                <div style="font-size: 48px; margin-bottom: 12px;">📄</div>
                <h4 style="font-size: 16px; font-weight: 600; margin-bottom: 8px;">PDF Document Receipt</h4>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                    This receipt was uploaded as a PDF file. You can open it in a new browser tab or download it directly.
                </p>
                <a id="receiptPdfOpenBtn" href="" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                    Open PDF in New Tab ↗
                </a>
            </div>
        </div>
        <div class="modal-footer">
            <a id="receiptExternalViewBtn" href="" target="_blank" rel="noopener noreferrer" class="btn btn-outline" style="margin-right: auto; display: none;">
                Open Full Size ↗
            </a>
            <button type="button" class="btn btn-outline" id="dismissReceiptBtn">Close</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Delete / Void Expense Dialog (Admin Only)                          -->
<!-- ========================================================================= -->
<?php if ($canDelete): ?>
<div class="modal-overlay" id="deleteExpenseModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="modal-card" style="max-width: 460px;">
        <div class="modal-header">
            <h3 class="modal-title" id="deleteModalTitle" style="color: var(--color-danger);">Void Expense Record</h3>
            <button type="button" class="modal-close" id="closeDeleteModal" aria-label="Close dialog">&times;</button>
        </div>
        <div class="modal-body">
            <div id="deleteExpenseAlert" style="display: none;" class="alert alert-danger"></div>
            
            <p style="font-size: 13.5px; color: var(--text-main); margin-bottom: 12px;">
                Are you sure you want to void this expense? In accordance with financial immutability standards, this record will be soft-deleted and retained in audit logs.
            </p>

            <div style="background: #fef2f2; border: 1px solid var(--color-danger-border); border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 16px;">
                <div style="font-weight: 600; font-size: 14px; color: var(--text-main);" id="deleteTargetTitle"></div>
                <div style="display: flex; justify-content: space-between; margin-top: 4px; font-size: 12.5px;">
                    <span style="color: var(--text-muted);" id="deleteTargetDate"></span>
                    <strong style="color: var(--color-danger);" class="tabular-nums" id="deleteTargetAmount"></strong>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="deleteReason">
                    Reason for Voiding <span style="color: var(--color-danger);">*</span>
                </label>
                <textarea class="form-textarea" id="deleteReason" rows="2" required minlength="3" maxlength="255" placeholder="Mandatory reason (min 3 characters, e.g. Duplicate entry, Wrong amount recorded)..."></textarea>
                <span style="font-size: 11.5px; color: var(--text-muted);">Required for financial audit trail compliance.</span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="cancelDeleteBtn">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteExpenseBtn">Confirm Void</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- CLIENT-SIDE SCRIPT: Data Fetching, Filter, Modals, Lightview & CRUD       -->
<!-- ========================================================================= -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const BASE_URL = '<?= BASE_URL ?>';
    const canManage = <?= $canManage ? 'true' : 'false' ?>;
    const canDelete = <?= $canDelete ? 'true' : 'false' ?>;

    // Filter elements
    const searchFilter = document.getElementById('searchFilter');
    const categoryFilter = document.getElementById('categoryFilter');
    const dateFromFilter = document.getElementById('dateFromFilter');
    const dateToFilter = document.getElementById('dateToFilter');
    const applyFiltersBtn = document.getElementById('applyFiltersBtn');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');

    // Table elements
    const tableBody = document.getElementById('expensesTableBody');
    const paginationInfo = document.getElementById('paginationInfo');
    const pageIndicator = document.getElementById('pageIndicator');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');

    // KPI Card elements
    const kpiMonthTotal = document.getElementById('kpiMonthTotal');
    const kpiTodayTotal = document.getElementById('kpiTodayTotal');
    const kpiTopCategory = document.getElementById('kpiTopCategory');
    const kpiTopCategoryTotal = document.getElementById('kpiTopCategoryTotal');
    const kpiTotalEntries = document.getElementById('kpiTotalEntries');

    // Receipt Modal elements
    const receiptModal = document.getElementById('receiptModal');
    const closeReceiptModal = document.getElementById('closeReceiptModal');
    const dismissReceiptBtn = document.getElementById('dismissReceiptBtn');
    const receiptImageWrapper = document.getElementById('receiptImageWrapper');
    const receiptModalImg = document.getElementById('receiptModalImg');
    const receiptPdfWrapper = document.getElementById('receiptPdfWrapper');
    const receiptPdfOpenBtn = document.getElementById('receiptPdfOpenBtn');
    const receiptExternalViewBtn = document.getElementById('receiptExternalViewBtn');
    const receiptModalTitle = document.getElementById('receiptModalTitle');

    // Record / Edit Modal elements (using addExpenseModal for slide-out drawer)
    const expenseModal = document.getElementById('addExpenseModal');
    const openAddExpenseBtn = document.getElementById('openAddExpenseBtn');
    const closeExpenseModal = document.getElementById('closeExpenseModal');
    const cancelExpenseBtn = document.getElementById('cancelExpenseBtn');
    const expenseForm = document.getElementById('expenseForm');
    const expenseModalTitle = document.getElementById('expenseModalTitle');
    const expenseModalAlert = document.getElementById('expenseModalAlert');
    const expenseIdInput = document.getElementById('expenseId');
    const expenseTitleInput = document.getElementById('expenseTitle');
    const expenseCategoryInput = document.getElementById('expenseCategory');
    const expenseAmountInput = document.getElementById('expenseAmount');
    const expenseDateInput = document.getElementById('expenseDate');
    const expenseReceiptInput = document.getElementById('expenseReceipt');
    const currentReceiptNotice = document.getElementById('currentReceiptNotice');
    const expenseNotesInput = document.getElementById('expenseNotes');
    const saveExpenseBtn = document.getElementById('saveExpenseBtn');

    // Delete Modal elements
    const deleteExpenseModal = document.getElementById('deleteExpenseModal');
    const closeDeleteModal = document.getElementById('closeDeleteModal');
    const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
    const confirmDeleteExpenseBtn = document.getElementById('confirmDeleteExpenseBtn');
    const deleteExpenseAlert = document.getElementById('deleteExpenseAlert');
    const deleteTargetTitle = document.getElementById('deleteTargetTitle');
    const deleteTargetDate = document.getElementById('deleteTargetDate');
    const deleteTargetAmount = document.getElementById('deleteTargetAmount');
    const deleteReason = document.getElementById('deleteReason');
    let pendingDeleteId = 0;

    let currentPage = 1;
    let totalPages = 1;

    // HTML Escape Helper
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Currency Formatter Helper
    function formatMoney(amount) {
        const num = parseFloat(amount) || 0;
        return '₱\u00A0' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Modal Visibility Helpers
    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Fetch and Render Expenses
    async function loadExpenses(page = 1) {
        currentPage = page;
        const params = new URLSearchParams({
            action: 'list_expenses',
            page: currentPage,
            limit: 15,
            search: searchFilter.value.trim(),
            category_id: categoryFilter.value,
            date_from: dateFromFilter.value,
            date_to: dateToFilter.value
        });

        tableBody.innerHTML = `
            <tr>
                <td colspan="${(canManage || canDelete) ? '7' : '6'}" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    <div style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
                    Loading expenses...
                </td>
            </tr>`;

        const res = await API.get('handler.php?' + params.toString());

        if (!res || !res.success || !res.data) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="${(canManage || canDelete) ? '7' : '6'}" style="text-align: center; padding: 40px 20px; color: var(--color-danger);">
                        Failed to load expenses. Please refresh or try again.
                    </td>
                </tr>`;
            return;
        }

        const { items, pagination, metrics } = res.data;

        // 1. Update KPI Cards dynamically
        if (metrics) {
            if (kpiMonthTotal) kpiMonthTotal.textContent = formatMoney(metrics.month_total);
            if (kpiTodayTotal) kpiTodayTotal.textContent = formatMoney(metrics.today_total);
            if (kpiTopCategory) {
                kpiTopCategory.textContent = metrics.top_category.name;
                kpiTopCategory.title = metrics.top_category.name;
            }
            if (kpiTopCategoryTotal) {
                kpiTopCategoryTotal.textContent = `${formatMoney(metrics.top_category.total)} this month`;
            }
            if (kpiTotalEntries) {
                kpiTotalEntries.textContent = parseInt(metrics.total_entries, 10).toLocaleString();
            }
        }

        // 2. Render Table Rows
        if (!items || items.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="${(canManage || canDelete) ? '7' : '6'}" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                        No expense records found matching the active criteria.
                    </td>
                </tr>`;
        } else {
            let html = '';
            items.forEach(item => {
                const dateStr = item.expense_date 
                    ? new Date(item.expense_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })
                    : '—';

                // Receipt badge/button
                let receiptCol = '<span class="badge badge-inactive" style="opacity: 0.6; font-size: 11px;">None</span>';
                if (item.receipt_path) {
                    const fullUrl = `${BASE_URL}/${item.receipt_path}`;
                    if (item.receipt_type === 'pdf') {
                        receiptCol = `
                            <button type="button" class="btn btn-outline btn-sm view-receipt-btn"
                                    data-url="${escapeHtml(fullUrl)}"
                                    data-type="pdf"
                                    data-title="${escapeHtml(item.title)}"
                                    style="padding: 2px 8px; font-size: 11px;"
                                    title="View PDF receipt">
                                📄 View PDF
                            </button>
                        `;
                    } else {
                        receiptCol = `
                            <button type="button" class="btn btn-outline btn-sm view-receipt-btn"
                                    data-url="${escapeHtml(fullUrl)}"
                                    data-type="image"
                                    data-title="${escapeHtml(item.title)}"
                                    style="padding: 2px 8px; font-size: 11px;"
                                    title="View receipt image">
                                📎 View Image
                            </button>
                        `;
                    }
                }

                html += `
                    <tr id="expense-row-${item.id}">
                        <td style="font-size: 13px; color: var(--text-muted); white-space: nowrap;">
                            ${dateStr}
                        </td>
                        <td>
                            <strong style="color: var(--text-main); font-size: 14px; font-weight: 600;">${escapeHtml(item.title)}</strong>
                        </td>
                        <td style="white-space: nowrap;">
                            <span class="badge badge-outline" style="font-size: 11.5px; font-weight: 500;">
                                ${escapeHtml(item.category_name)}
                            </span>
                        </td>
                        <td style="font-size: 13px; color: var(--text-muted); white-space: nowrap;">
                            ${escapeHtml(item.user_name)}
                        </td>
                        <td style="text-align: center; white-space: nowrap;">
                            ${receiptCol}
                        </td>
                        <td style="text-align: right; font-weight: 700; color: var(--color-danger); white-space: nowrap;" class="tabular-nums">
                            ${formatMoney(item.amount)}
                        </td>
                        ${(canManage || canDelete) ? `
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                    ${canManage ? `
                                        <button type="button" class="btn btn-outline btn-sm edit-expense-btn"
                                                data-id="${item.id}"
                                                title="Edit expense">
                                            <svg viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; margin-right: 4px; vertical-align: -1px;"><path d="M4 20h4L19 9l-4-4L4 16v4z"/></svg>Edit
                                        </button>
                                    ` : ''}
                                    ${canDelete ? `
                                        <button type="button" class="btn btn-outline btn-sm delete-expense-btn"
                                                style="color: var(--color-danger); border-color: var(--color-danger-border);"
                                                data-id="${item.id}"
                                                data-title="${escapeHtml(item.title)}"
                                                data-amount="${item.amount}"
                                                data-date="${dateStr}"
                                                title="Void expense record">
                                            <svg viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; margin-right: 4px; vertical-align: -1px;"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>Void
                                        </button>
                                    ` : ''}
                                </div>
                            </td>
                        ` : ''}
                    </tr>
                `;
            });
            tableBody.innerHTML = html;
        }

        // 3. Update Pagination Controls
        if (pagination) {
            totalPages = pagination.total_pages || 1;
            const totalRecs = pagination.total_records || 0;
            const start = totalRecs === 0 ? 0 : ((pagination.page - 1) * pagination.limit) + 1;
            const end = Math.min(pagination.page * pagination.limit, totalRecs);

            paginationInfo.textContent = `Showing ${start} to ${end} of ${totalRecs} entries`;
            pageIndicator.textContent = `Page ${pagination.page} of ${totalPages}`;

            prevPageBtn.disabled = pagination.page <= 1;
            nextPageBtn.disabled = pagination.page >= totalPages;
        }
    }

    // Initial Load
    loadExpenses(1);

    // Filter Listeners
    applyFiltersBtn.addEventListener('click', () => loadExpenses(1));
    resetFiltersBtn.addEventListener('click', () => {
        searchFilter.value = '';
        categoryFilter.value = '';
        dateFromFilter.value = '';
        dateToFilter.value = '';
        loadExpenses(1);
    });

    searchFilter.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            loadExpenses(1);
        }
    });

    // Pagination Listeners
    prevPageBtn.addEventListener('click', () => {
        if (currentPage > 1) loadExpenses(currentPage - 1);
    });

    nextPageBtn.addEventListener('click', () => {
        if (currentPage < totalPages) loadExpenses(currentPage + 1);
    });

    // =========================================================================
    // Receipt Lightview Logic
    // =========================================================================
    document.getElementById('expensesTable').addEventListener('click', (e) => {
        const receiptBtn = e.target.closest('.view-receipt-btn');
        if (!receiptBtn) return;

        const url = receiptBtn.dataset.url;
        const type = receiptBtn.dataset.type;
        const title = receiptBtn.dataset.title;

        receiptModalTitle.textContent = `Receipt: ${title}`;

        if (type === 'image') {
            receiptImageWrapper.style.display = 'block';
            receiptModalImg.src = url;
            receiptPdfWrapper.style.display = 'none';
            receiptExternalViewBtn.href = url;
            receiptExternalViewBtn.style.display = 'inline-flex';
        } else if (type === 'pdf') {
            receiptImageWrapper.style.display = 'none';
            receiptModalImg.src = '';
            receiptPdfWrapper.style.display = 'block';
            receiptPdfOpenBtn.href = url;
            receiptExternalViewBtn.style.display = 'none';
        }

        openModal(receiptModal);
    });

    closeReceiptModal.addEventListener('click', () => closeModal(receiptModal));
    dismissReceiptBtn.addEventListener('click', () => closeModal(receiptModal));

    // =========================================================================
    // Record / Edit Expense Logic
    // =========================================================================
    if (canManage && openAddExpenseBtn) {
        openAddExpenseBtn.addEventListener('click', () => {
            expenseModalTitle.textContent = 'Record Expense';
            expenseIdInput.value = '0';
            expenseForm.reset();
            expenseModalAlert.style.display = 'none';
            currentReceiptNotice.style.display = 'none';
            
            // Set default date to today
            const today = new Date().toISOString().split('T')[0];
            expenseDateInput.value = today;

            openModal(expenseModal);
            setTimeout(() => expenseTitleInput.focus(), 50);
        });

        closeExpenseModal.addEventListener('click', () => closeModal(expenseModal));
        cancelExpenseBtn.addEventListener('click', () => closeModal(expenseModal));

        // Delegated Edit Button
        document.getElementById('expensesTable').addEventListener('click', async (e) => {
            const editBtn = e.target.closest('.edit-expense-btn');
            if (!editBtn) return;

            const id = parseInt(editBtn.dataset.id, 10);
            if (!id) return;

            expenseModalTitle.textContent = 'Edit Expense Record';
            expenseModalAlert.style.display = 'none';
            expenseForm.reset();

            const res = await API.get(`handler.php?action=get_expense&id=${id}`);
            if (!res || !res.success || !res.data) {
                API.toast(res ? res.message : 'Failed to retrieve expense details.', 'error');
                return;
            }

            const exp = res.data;
            expenseIdInput.value = exp.id;
            expenseTitleInput.value = exp.title;
            expenseCategoryInput.value = exp.category_id;
            expenseAmountInput.value = exp.amount;
            expenseDateInput.value = exp.expense_date;
            expenseNotesInput.value = exp.notes || '';

            if (exp.receipt_path) {
                currentReceiptNotice.textContent = `Attached file: ${exp.receipt_path.split('/').pop()} (upload new to replace)`;
                currentReceiptNotice.style.display = 'block';
            } else {
                currentReceiptNotice.style.display = 'none';
            }

            openModal(expenseModal);
            setTimeout(() => expenseTitleInput.focus(), 50);
        });

        // Form Submit
        expenseForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            expenseModalAlert.style.display = 'none';

            const title = expenseTitleInput.value.trim();
            const categoryId = parseInt(expenseCategoryInput.value, 10);
            const amount = parseFloat(expenseAmountInput.value);
            const expenseDate = expenseDateInput.value.trim();

            if (!title) {
                expenseModalAlert.textContent = 'Please provide an expense title.';
                expenseModalAlert.style.display = 'block';
                return;
            }
            if (!categoryId) {
                expenseModalAlert.textContent = 'Please select a category.';
                expenseModalAlert.style.display = 'block';
                return;
            }
            if (isNaN(amount) || amount <= 0) {
                expenseModalAlert.textContent = 'Amount must be a positive number greater than 0.';
                expenseModalAlert.style.display = 'block';
                return;
            }
            if (!expenseDate) {
                expenseModalAlert.textContent = 'Expense date is required.';
                expenseModalAlert.style.display = 'block';
                return;
            }

            const id = parseInt(expenseIdInput.value, 10);
            const action = id > 0 ? 'update_expense' : 'create_expense';

            saveExpenseBtn.disabled = true;
            saveExpenseBtn.textContent = 'Saving...';

            const formData = new FormData(expenseForm);
            formData.set('action', action);

            const res = await API.post('handler.php', formData);
            saveExpenseBtn.disabled = false;
            saveExpenseBtn.textContent = 'Save Expense';

            if (res && res.success) {
                API.toast(res.message || 'Expense recorded successfully.', 'success');
                closeModal(expenseModal);
                loadExpenses(currentPage);
            } else {
                let msg = (res && res.message) ? res.message : 'Unable to save expense.';
                if (res && res.errors) {
                    const errorList = Object.values(res.errors).join(' ');
                    if (errorList) msg += ` (${errorList})`;
                }
                expenseModalAlert.textContent = msg;
                expenseModalAlert.style.display = 'block';
            }
        });
    }

    // =========================================================================
    // Void / Delete Expense Logic (Admin Only)
    // =========================================================================
    if (canDelete && deleteExpenseModal) {
        closeDeleteModal.addEventListener('click', () => closeModal(deleteExpenseModal));
        cancelDeleteBtn.addEventListener('click', () => closeModal(deleteExpenseModal));

        document.getElementById('expensesTable').addEventListener('click', (e) => {
            const delBtn = e.target.closest('.delete-expense-btn');
            if (!delBtn) return;

            pendingDeleteId = parseInt(delBtn.dataset.id, 10);
            deleteTargetTitle.textContent = delBtn.dataset.title;
            deleteTargetDate.textContent = `Date: ${delBtn.dataset.date}`;
            deleteTargetAmount.textContent = formatMoney(delBtn.dataset.amount);
            deleteReason.value = '';
            deleteExpenseAlert.style.display = 'none';

            openModal(deleteExpenseModal);
            setTimeout(() => deleteReason.focus(), 50);
        });

        confirmDeleteExpenseBtn.addEventListener('click', async () => {
            if (!pendingDeleteId) return;

            const reason = deleteReason.value.trim();
            if (reason.length < 3) {
                deleteExpenseAlert.textContent = 'Please provide a valid deletion reason (at least 3 characters).';
                deleteExpenseAlert.style.display = 'block';
                return;
            }

            confirmDeleteExpenseBtn.disabled = true;
            confirmDeleteExpenseBtn.textContent = 'Voiding...';

            const res = await API.post('handler.php', {
                action: 'delete_expense',
                id: pendingDeleteId,
                reason: reason
            });

            confirmDeleteExpenseBtn.disabled = false;
            confirmDeleteExpenseBtn.textContent = 'Confirm Void';

            if (res && res.success) {
                API.toast('Expense record voided successfully.', 'success');
                closeModal(deleteExpenseModal);
                pendingDeleteId = 0;
                loadExpenses(currentPage);
            } else {
                deleteExpenseAlert.textContent = (res && res.message) ? res.message : 'Failed to void expense record.';
                deleteExpenseAlert.style.display = 'block';
            }
        });
    }

    // Overlay backdrop clicks to close (only if click originated on backdrop)
    let expenseMouseDownTarget = null;
    document.addEventListener('mousedown', (e) => {
        expenseMouseDownTarget = e.target;
    });
    [expenseModal, receiptModal, deleteExpenseModal].forEach(modal => {
        if (!modal) return;
        modal.addEventListener('click', (e) => {
            if (e.target === modal && expenseMouseDownTarget === modal) {
                closeModal(modal);
            }
        });
    });

    // Global ESC key listener for open modals
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal(expenseModal);
            closeModal(receiptModal);
            closeModal(deleteExpenseModal);
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
