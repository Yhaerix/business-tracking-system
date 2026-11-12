<?php
/**
 * Financial Performance Reports & Analytics View
 * Small Business Sales and Expense Tracking System
 * Reference: docs/UI_DESIGN_SPEC.md, docs/BUSINESS_RULES.md
 */

declare(strict_types=1);

$pageTitle = 'Financial Performance Reports';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_permission('reports_view');

$canExport = has_permission('reports_export');
$db = get_db();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-content">
    <!-- Header with Export Action -->
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h1 class="page-title" style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0 0 4px 0;">
                Financial Performance Reports
            </h1>
            <p style="margin: 0; color: var(--text-muted); font-size: 13.5px;">
                Monitor daily/monthly net income, realized COD sales, operational cost distribution, and physical cash flow.
            </p>
        </div>

        <?php if ($canExport): ?>
            <div>
                <button type="button" id="btnExportCsv" class="btn btn-primary">
                    📥 Export CSV
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Date Range Filter Toolbar -->
    <div class="card" style="padding: 18px 20px; margin-bottom: 24px;">
        <form id="reportFilterForm" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
            <!-- Range Presets -->
            <div style="flex: 2; min-width: 280px;">
                <label class="form-label" style="margin-bottom: 6px;">Quick Presets</label>
                <div class="btn-group" role="group" style="display: flex; flex-wrap: wrap; gap: 6px;">
                    <button type="button" class="btn btn-sm btn-outline preset-btn" data-preset="today">Today</button>
                    <button type="button" class="btn btn-sm btn-outline preset-btn" data-preset="this_week">This Week</button>
                    <button type="button" class="btn btn-sm btn-primary preset-btn active" data-preset="this_month">This Month</button>
                    <button type="button" class="btn btn-sm btn-outline preset-btn" data-preset="this_year">This Year</button>
                </div>
            </div>

            <!-- Custom Date Controls -->
            <div style="flex: 1; min-width: 140px;">
                <label class="form-label" for="dateFrom" style="margin-bottom: 5px;">Date From</label>
                <input type="date" id="dateFrom" class="form-input">
            </div>

            <div style="flex: 1; min-width: 140px;">
                <label class="form-label" for="dateTo" style="margin-bottom: 5px;">Date To</label>
                <input type="date" id="dateTo" class="form-input">
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary" style="height: 38px;">Apply Filter</button>
                <button type="button" id="btnResetFilter" class="btn btn-outline" style="height: 38px;">Reset</button>
            </div>
        </form>
    </div>

    <!-- Active Filter Display Banner -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <div style="font-size: 14px; font-weight: 600; color: var(--text-main);" id="activePeriodLabel">
            Reporting Period: <span style="color: var(--color-primary);">Loading...</span>
        </div>
        <div style="font-size: 12px; color: var(--color-success); font-weight: 600; background: var(--color-success-bg); padding: 4px 10px; border-radius: var(--radius-full); border: 1px solid var(--color-success-border);">
            🛡️ Strictly Zero-Tax Applied (0% Tax)
        </div>
    </div>

    <!-- Financial KPI Summary Cards (Realized Sales, Expenses, Net Income) -->
    <div class="stats-grid" id="kpiStatsGrid">
        <!-- 1. Realized Gross Sales -->
        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Realized Gross Sales</span>
                <div class="stat-icon icon-success">💰</div>
            </div>
            <div class="stat-value" style="color: var(--color-success);" id="statGrossSales">
                <?= APP_CURRENCY ?>0.00
            </div>
            <div class="stat-subtext" id="statOrdersCount">
                0 delivered orders
            </div>
        </div>

        <!-- 2. Total Operating Expenses -->
        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Total Operating Costs</span>
                <div class="stat-icon icon-danger">📉</div>
            </div>
            <div class="stat-value" style="color: var(--color-danger);" id="statTotalExpenses">
                <?= APP_CURRENCY ?>0.00
            </div>
            <div class="stat-subtext" id="statExpensesCount">
                0 recorded expenditures
            </div>
        </div>

        <!-- 3. Net Income (Profit / Loss) -->
        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Net Income (Net Profit)</span>
                <div class="stat-icon icon-primary">📊</div>
            </div>
            <div class="stat-value" id="statNetIncome">
                <?= APP_CURRENCY ?>0.00
            </div>
            <div class="stat-subtext" id="statNetMargin">
                Margin: 0.0% of realized revenue
            </div>
        </div>

        <!-- 4. COD Remitted Cash Flow -->
        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Remitted to Store Drawer</span>
                <div class="stat-icon icon-primary">🏦</div>
            </div>
            <div class="stat-value" style="color: var(--color-primary);" id="statCashRemitted">
                <?= APP_CURRENCY ?>0.00
            </div>
            <div class="stat-subtext" id="statUnremittedCash">
                Unremitted in courier hands: <?= APP_CURRENCY ?>0.00
            </div>
        </div>
    </div>

    <!-- Report Breakdown Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 24px; margin-bottom: 24px;">
        <!-- Left Pane: Operating Expense Breakdown by Category -->
        <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 0;">
            <div class="card-header" style="padding: 16px 20px; margin-bottom: 0; border-bottom: 1px solid var(--border-color);">
                <div class="card-title" style="font-size: 15px;">📂 Operating Expenses by Category</div>
            </div>

            <div class="table-responsive" style="border: none; border-radius: 0;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-center" style="width: 80px;">Entries</th>
                            <th class="text-right" style="width: 130px;">Amount</th>
                            <th style="width: 140px;">Proportion</th>
                        </tr>
                    </thead>
                    <tbody id="categoryTableBody">
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 24px; color: var(--text-muted);">
                                Loading category distribution...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right Pane: Daily Net Income & Cash Flow Timeline -->
        <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 0;">
            <div class="card-header" style="padding: 16px 20px; margin-bottom: 0; border-bottom: 1px solid var(--border-color);">
                <div class="card-title" style="font-size: 15px;">📅 Daily Cash Flow &amp; Net Margin</div>
            </div>

            <div class="table-responsive" style="border: none; border-radius: 0;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-right" style="color: var(--color-success);">Sales</th>
                            <th class="text-right" style="color: var(--color-danger);">Expenses</th>
                            <th class="text-right">Net Income</th>
                        </tr>
                    </thead>
                    <tbody id="timelineTableBody">
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 24px; color: var(--text-muted);">
                                Loading daily timeline...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    let currentPreset = 'this_month';
    const presetButtons = document.querySelectorAll('.preset-btn');
    const dateFromInput = document.getElementById('dateFrom');
    const dateToInput = document.getElementById('dateTo');
    const filterForm = document.getElementById('reportFilterForm');
    const resetBtn = document.getElementById('btnResetFilter');
    const btnExportCsv = document.getElementById('btnExportCsv');
    const activePeriodLabel = document.getElementById('activePeriodLabel');

    // KPI Elements
    const statGrossSales = document.getElementById('statGrossSales');
    const statOrdersCount = document.getElementById('statOrdersCount');
    const statTotalExpenses = document.getElementById('statTotalExpenses');
    const statExpensesCount = document.getElementById('statExpensesCount');
    const statNetIncome = document.getElementById('statNetIncome');
    const statNetMargin = document.getElementById('statNetMargin');
    const statCashRemitted = document.getElementById('statCashRemitted');
    const statUnremittedCash = document.getElementById('statUnremittedCash');

    // Tables
    const categoryTableBody = document.getElementById('categoryTableBody');
    const timelineTableBody = document.getElementById('timelineTableBody');

    function formatCurrency(val) {
        return '<?= APP_CURRENCY ?>' + parseFloat(val || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    async function loadReport() {
        const params = new URLSearchParams({
            action: 'financial_summary',
            range: currentPreset,
            date_from: dateFromInput.value,
            date_to: dateToInput.value
        });

        try {
            const res = await API.get(`handler.php?${params.toString()}`);
            if (!res.success) {
                alert(res.message || 'Failed to load report data.');
                return;
            }

            const { range, summary, categories, timeline } = res.data;

            // Update Label & Inputs
            activePeriodLabel.innerHTML = `Reporting Period: <strong style="color: var(--color-primary);">${escapeHtml(range.label)}</strong>`;
            if (currentPreset !== 'custom') {
                dateFromInput.value = range.date_from;
                dateToInput.value = range.date_to;
            }

            // Update Summary KPI Cards
            statGrossSales.textContent = formatCurrency(summary.gross_sales);
            statOrdersCount.textContent = `${summary.delivered_orders_count} delivered orders`;

            statTotalExpenses.textContent = formatCurrency(summary.operating_expenses);
            statExpensesCount.textContent = `${summary.expenses_count} recorded expenditures`;

            statNetIncome.textContent = formatCurrency(summary.net_income);
            statNetIncome.style.color = summary.net_income >= 0 ? 'var(--color-success)' : 'var(--color-danger)';
            statNetMargin.textContent = `Margin: ${summary.net_margin_percentage}% of realized revenue`;

            statCashRemitted.textContent = formatCurrency(summary.cash_remitted);
            statUnremittedCash.textContent = `Unremitted in courier hands: ${formatCurrency(summary.unremitted_cod_cash)}`;

            // Render Category Table
            if (!categories || categories.length === 0) {
                categoryTableBody.innerHTML = `
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 24px; color: var(--text-muted);">
                            No operating expenses incurred in this period.
                        </td>
                    </tr>
                `;
            } else {
                let catHtml = '';
                categories.forEach(c => {
                    catHtml += `
                        <tr>
                            <td><strong>${escapeHtml(c.category_name)}</strong></td>
                            <td class="text-center">${c.entry_count}</td>
                            <td class="text-right tabular-nums" style="font-weight: 600;">${formatCurrency(c.category_total)}</td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; background: #e2e8f0; height: 6px; border-radius: 3px; overflow: hidden;">
                                        <div style="width: ${c.percentage}%; background: var(--color-primary); height: 100%;"></div>
                                    </div>
                                    <span style="font-size: 11.5px; font-weight: 600; width: 36px; text-align: right;">${c.percentage}%</span>
                                </div>
                            </td>
                        </tr>
                    `;
                });
                categoryTableBody.innerHTML = catHtml;
            }

            // Render Timeline Table
            if (!timeline || timeline.length === 0) {
                timelineTableBody.innerHTML = `
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 24px; color: var(--text-muted);">
                            No financial activity during this period.
                        </td>
                    </tr>
                `;
            } else {
                let timeHtml = '';
                timeline.forEach(t => {
                    const isNetPositive = t.net >= 0;
                    timeHtml += `
                        <tr>
                            <td><span style="font-family: monospace; font-size: 13px;">${escapeHtml(t.date)}</span></td>
                            <td class="text-right tabular-nums" style="color: var(--color-success); font-weight: 500;">
                                ${formatCurrency(t.sales)}
                            </td>
                            <td class="text-right tabular-nums" style="color: var(--color-danger); font-weight: 500;">
                                ${formatCurrency(t.expenses)}
                            </td>
                            <td class="text-right tabular-nums" style="font-weight: 700; color: ${isNetPositive ? 'var(--color-success)' : 'var(--color-danger)'};">
                                ${formatCurrency(t.net)}
                            </td>
                        </tr>
                    `;
                });
                timelineTableBody.innerHTML = timeHtml;
            }

        } catch (err) {
            console.error('Report fetch error:', err);
            alert('Error communicating with backend server.');
        }
    }

    // Preset button handlers
    presetButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            presetButtons.forEach(b => {
                b.classList.remove('btn-primary', 'active');
                b.classList.add('btn-outline');
            });
            btn.classList.remove('btn-outline');
            btn.classList.add('btn-primary', 'active');

            currentPreset = btn.getAttribute('data-preset');
            loadReport();
        });
    });

    filterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        currentPreset = 'custom';
        presetButtons.forEach(b => {
            b.classList.remove('btn-primary', 'active');
            b.classList.add('btn-outline');
        });
        loadReport();
    });

    resetBtn.addEventListener('click', () => {
        currentPreset = 'this_month';
        presetButtons.forEach(b => {
            if (b.getAttribute('data-preset') === 'this_month') {
                b.classList.remove('btn-outline');
                b.classList.add('btn-primary', 'active');
            } else {
                b.classList.remove('btn-primary', 'active');
                b.classList.add('btn-outline');
            }
        });
        dateFromInput.value = '';
        dateToInput.value = '';
        loadReport();
    });

    // CSV Export trigger
    if (btnExportCsv) {
        btnExportCsv.addEventListener('click', () => {
            const params = new URLSearchParams({
                action: 'export_csv',
                range: currentPreset,
                date_from: dateFromInput.value,
                date_to: dateToInput.value
            });
            window.location.href = `handler.php?${params.toString()}`;
        });
    }

    // Initial load
    loadReport();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

