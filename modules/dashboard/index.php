<?php
/**
 * Main Application Dashboard (Live Financials & KPIs)
 * Small Business Sales and Expense Tracking System
 * Reference: docs/UI_DESIGN_SPEC.md, docs/BUSINESS_RULES.md
 */

declare(strict_types=1);

$pageTitle = 'Dashboard';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
$user = current_user();
$db = get_db();

// 1. Today's Realized Gross Sales (DELIVERED orders today)
$stmtTodaySales = $db->query("
    SELECT 
        COUNT(*) as order_count,
        COALESCE(SUM(total_amount), 0.00) as gross_sales
    FROM orders 
    WHERE order_status = 'DELIVERED' 
      AND DATE(COALESCE(delivered_at, created_at)) = CURRENT_DATE
");
$todaySalesRow = $stmtTodaySales->fetch() ?: [];
$todaySales = (float) ($todaySalesRow['gross_sales'] ?? 0.00);
$todayDeliveredCount = (int) ($todaySalesRow['order_count'] ?? 0);

// 2. Pending COD Collections (Confirmed or Dispatched orders awaiting delivery/cash)
$stmtPendingCod = $db->query("
    SELECT 
        COUNT(*) as pending_count,
        COALESCE(SUM(o.total_amount), 0.00) as pending_amount
    FROM orders o
    WHERE o.order_status IN ('CONFIRMED', 'DISPATCHED')
");
$pendingCodRow = $stmtPendingCod->fetch() ?: [];
$pendingCodAmount = (float) ($pendingCodRow['pending_amount'] ?? 0.00);
$pendingCodCount = (int) ($pendingCodRow['pending_count'] ?? 0);

// 3. Today's Operating Expenses (Active expenses incurred today)
$todayExpenses = 0.00;
$todayExpensesCount = 0;
if (has_permission('expenses_view')) {
    $stmtTodayExp = $db->query("
        SELECT 
            COUNT(*) as exp_count,
            COALESCE(SUM(amount), 0.00) as total_expenses
        FROM expenses 
        WHERE deleted_at IS NULL 
          AND expense_date = CURRENT_DATE
    ");
    $todayExpRow = $stmtTodayExp->fetch() ?: [];
    $todayExpenses = (float) ($todayExpRow['total_expenses'] ?? 0.00);
    $todayExpensesCount = (int) ($todayExpRow['exp_count'] ?? 0);
}

// 4. Physical Cash on Hand (Total REMITTED COD Cash settled into the store)
$stmtCashOnHand = $db->query("
    SELECT COALESCE(SUM(amount_due), 0.00) as remitted_cash
    FROM cod_payments 
    WHERE status = 'REMITTED'
");
$cashOnHand = (float) $stmtCashOnHand->fetchColumn();

// 5. Today's Net Income (Realized Sales - Operating Expenses)
$todayNetIncome = round($todaySales - $todayExpenses, 2);

// 6. Low Stock Inventory Count & Alerts
$stmtLowStock = $db->query("
    SELECT id, name, sku, current_stock, low_stock_threshold 
    FROM products 
    WHERE deleted_at IS NULL 
      AND current_stock <= low_stock_threshold 
    ORDER BY current_stock ASC 
    LIMIT 5
");
$lowStockProducts = $stmtLowStock->fetchAll();
$lowStockCount = count($lowStockProducts);

// 7. Recent COD Orders (Latest 5)
$stmtRecentOrders = $db->query("
    SELECT id, order_number, customer_name, total_amount, order_status, created_at
    FROM orders 
    ORDER BY id DESC 
    LIMIT 5
");
$recentOrders = $stmtRecentOrders->fetchAll();

// 8. Recent Operational Expenses (Latest 5, Admin/Manager only)
$recentExpenses = [];
if (has_permission('expenses_view')) {
    $stmtRecentExp = $db->query("
        SELECT e.id, e.title, e.amount, e.expense_date, c.name as category_name
        FROM expenses e
        JOIN expense_categories c ON e.category_id = c.id
        WHERE e.deleted_at IS NULL 
        ORDER BY e.id DESC 
        LIMIT 5
    ");
    $recentExpenses = $stmtRecentExp->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-content">
    <!-- Welcoming Header Banner -->
    <div class="card" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #ffffff; border: none; margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 4px 0; color: #ffffff;">
                    Welcome back, <?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>!
                </h2>
                <p style="color: #94a3b8; font-size: 13.5px; margin: 0;">
                    Role: <strong><?= htmlspecialchars($user['role_label'], ENT_QUOTES, 'UTF-8') ?></strong> &bull; <?= APP_NAME ?>
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?= BASE_URL ?>/modules/orders/create.php" class="btn btn-primary">
                    🛒 New Sale / POS
                </a>
                <?php if (has_permission('expenses_manage')): ?>
                    <a href="<?= BASE_URL ?>/modules/expenses/" class="btn btn-outline" style="color: #ffffff; border-color: rgba(255,255,255,0.25);">
                        💸 Record Expense
                    </a>
                <?php endif; ?>
                <?php if (has_permission('reports_view')): ?>
                    <a href="<?= BASE_URL ?>/modules/reports/" class="btn btn-outline" style="color: #ffffff; border-color: rgba(255,255,255,0.25);">
                        📊 Financial Reports
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 4 Top KPI Stat Cards -->
    <div class="stats-grid">
        <!-- Stat 1: Today's Realized Sales -->
        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Today's Realized Sales</span>
                <div class="stat-icon icon-success">💰</div>
            </div>
            <div class="stat-value" style="color: var(--color-success);">
                <?= APP_CURRENCY ?><?= number_format($todaySales, 2) ?>
            </div>
            <div class="stat-subtext">
                <?= number_format($todayDeliveredCount) ?> orders delivered &amp; collected today
            </div>
        </div>

        <!-- Stat 2: Pending COD Collections -->
        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Pending COD Out</span>
                <div class="stat-icon icon-warning">🚚</div>
            </div>
            <div class="stat-value" style="color: var(--color-warning);">
                <?= APP_CURRENCY ?><?= number_format($pendingCodAmount, 2) ?>
            </div>
            <div class="stat-subtext">
                <?= number_format($pendingCodCount) ?> orders out for COD collection
            </div>
        </div>

        <!-- Stat 3: Cash on Hand (Remitted Cash) -->
        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Cash on Hand (Remitted)</span>
                <div class="stat-icon icon-primary">🏦</div>
            </div>
            <div class="stat-value" style="color: var(--color-primary);">
                <?= APP_CURRENCY ?><?= number_format($cashOnHand, 2) ?>
            </div>
            <div class="stat-subtext">
                Physical cash remitted to drawer
            </div>
        </div>

        <!-- Stat 4: Low Stock Alert Items -->
        <div class="card-stat">
            <div class="stat-header">
                <span class="stat-label">Low Stock Alerts</span>
                <div class="stat-icon <?= $lowStockCount > 0 ? 'icon-danger' : 'icon-success' ?>">
                    <?= $lowStockCount > 0 ? '⚠️' : '📦' ?>
                </div>
            </div>
            <div class="stat-value" style="<?= $lowStockCount > 0 ? 'color: var(--color-danger);' : '' ?>">
                <?= number_format($lowStockCount) ?>
            </div>
            <div class="stat-subtext">
                <?= $lowStockCount > 0 ? 'Products at or below min threshold' : 'All inventory levels healthy' ?>
            </div>
        </div>
    </div>

    <!-- Secondary Metric Bar for Admin / Manager: Today's Expenses & Net Income -->
    <?php if (has_permission('dashboard_financials')): ?>
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-bottom: 24px;">
            <div class="card-stat" style="background: #f8fafc;">
                <div class="stat-header">
                    <span class="stat-label">Today's Operating Expenses</span>
                    <div class="stat-icon icon-danger">📉</div>
                </div>
                <div class="stat-value" style="color: var(--color-danger);">
                    <?= APP_CURRENCY ?><?= number_format($todayExpenses, 2) ?>
                </div>
                <div class="stat-subtext">
                    <?= number_format($todayExpensesCount) ?> expenses recorded today
                </div>
            </div>

            <div class="card-stat" style="background: #f8fafc;">
                <div class="stat-header">
                    <span class="stat-label">Today's Estimated Net Income</span>
                    <div class="stat-icon icon-primary">📈</div>
                </div>
                <div class="stat-value" style="color: <?= $todayNetIncome >= 0 ? 'var(--color-success)' : 'var(--color-danger)' ?>;">
                    <?= APP_CURRENCY ?><?= number_format($todayNetIncome, 2) ?>
                </div>
                <div class="stat-subtext">
                    Realized Sales (<?= APP_CURRENCY ?><?= number_format($todaySales, 2) ?>) &minus; Expenses (<?= APP_CURRENCY ?><?= number_format($todayExpenses, 2) ?>)
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Low Stock Critical Alert Banner (Visible if low stock exists) -->
    <?php if ($lowStockCount > 0): ?>
        <div class="card" style="border-left: 4px solid var(--color-danger); background: var(--color-danger-bg); margin-bottom: 24px; padding: 18px 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 24px;">⚠️</span>
                    <div>
                        <strong style="color: #991b1b; font-size: 14.5px;">Attention: <?= $lowStockCount ?> item(s) are critically low on stock!</strong>
                        <p style="margin: 2px 0 0 0; font-size: 13px; color: #7f1d1d;">
                            Replenish inventory to avoid stockouts on pending COD orders.
                        </p>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/modules/inventory/" class="btn btn-sm btn-danger">
                    📦 Open Stock Ledger
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Content Split Grid: Recent Orders & Recent Expenses -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 24px; margin-bottom: 24px;">
        <!-- Left: Recent Orders -->
        <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 0;">
            <div class="card-header" style="padding: 16px 20px; margin-bottom: 0; border-bottom: 1px solid var(--border-color);">
                <div class="card-title" style="font-size: 15px;">📦 Recent COD Orders</div>
                <a href="<?= BASE_URL ?>/modules/orders/" class="btn btn-outline btn-sm">View All</a>
            </div>

            <div class="table-responsive" style="border: none; border-radius: 0;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th class="text-right">Total</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentOrders)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 24px; color: var(--text-muted);">
                                    No orders recorded yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentOrders as $ord): 
                                $badgeClass = 'badge-pending';
                                if ($ord['order_status'] === 'DELIVERED') $badgeClass = 'badge-delivered';
                                elseif ($ord['order_status'] === 'DISPATCHED') $badgeClass = 'badge-dispatched';
                                elseif ($ord['order_status'] === 'CONFIRMED') $badgeClass = 'badge-confirmed';
                                elseif ($ord['order_status'] === 'CANCELLED') $badgeClass = 'badge-cancelled';
                            ?>
                                <tr>
                                    <td>
                                        <a href="<?= BASE_URL ?>/modules/orders/?search=<?= urlencode($ord['order_number']) ?>" style="font-family: monospace; font-weight: 600; color: var(--color-primary); text-decoration: none;">
                                            <?= htmlspecialchars($ord['order_number'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500; font-size: 13px;"><?= htmlspecialchars($ord['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div style="font-size: 11.5px; color: var(--text-muted);"><?= substr((string)$ord['created_at'], 0, 10) ?></div>
                                    </td>
                                    <td class="text-right tabular-nums" style="font-weight: 600;">
                                        <?= APP_CURRENCY ?><?= number_format((float)$ord['total_amount'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($ord['order_status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Recent Expenses (If Permitted) or Quick Navigation -->
        <?php if (has_permission('expenses_view')): ?>
            <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 0;">
                <div class="card-header" style="padding: 16px 20px; margin-bottom: 0; border-bottom: 1px solid var(--border-color);">
                    <div class="card-title" style="font-size: 15px;">💸 Recent Operating Expenses</div>
                    <a href="<?= BASE_URL ?>/modules/expenses/" class="btn btn-outline btn-sm">View All</a>
                </div>

                <div class="table-responsive" style="border: none; border-radius: 0;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Expense Title</th>
                                <th>Category</th>
                                <th>Date</th>
                                <th class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentExpenses)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 24px; color: var(--text-muted);">
                                        No expenses recorded yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentExpenses as $exp): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; font-size: 13px;"><?= htmlspecialchars($exp['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td>
                                            <span style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($exp['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                        <td style="font-size: 12px; color: var(--text-muted);">
                                            <?= htmlspecialchars($exp['expense_date'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td class="text-right tabular-nums" style="font-weight: 600; color: var(--color-danger);">
                                            <?= APP_CURRENCY ?><?= number_format((float)$exp['amount'], 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <!-- Fallback for Cashier: Operational Quick Links -->
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header" style="margin-bottom: 16px;">
                    <h3 class="card-title">🚀 Fast Navigation</h3>
                </div>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <a href="<?= BASE_URL ?>/modules/orders/create.php" class="btn btn-primary" style="justify-content: flex-start; padding: 12px 16px;">
                        🛒 Open POS / Create COD Order
                    </a>
                    <a href="<?= BASE_URL ?>/modules/orders/" class="btn btn-outline" style="justify-content: flex-start; padding: 12px 16px;">
                        📦 Check Dispatched &amp; Delivered Orders
                    </a>
                    <a href="<?= BASE_URL ?>/modules/products/" class="btn btn-outline" style="justify-content: flex-start; padding: 12px 16px;">
                        🏷️ Browse Product Catalog &amp; Pricing
                    </a>
                    <a href="<?= BASE_URL ?>/modules/invoices/" class="btn btn-outline" style="justify-content: flex-start; padding: 12px 16px;">
                        📄 View Invoices &amp; Print Thermal Receipts
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
