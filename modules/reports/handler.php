<?php
/**
 * Financial Reports & CSV Export Backend Handler
 * Small Business Sales and Expense Tracking System
 * Reference: docs/UI_DESIGN_SPEC.md, docs/BUSINESS_RULES.md
 *
 * Actions:
 * - financial_summary (GET/POST, Permission: reports_view)
 * - export_csv        (GET/POST, Permission: reports_export)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/audit.php';

// Helper: Standardized JSON envelope
function send_json(bool $success, string $message, $data = null, array $errors = [], int $statusCode = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
        'errors'  => $errors
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ensure caller is authenticated
if (!is_logged_in()) {
    send_json(false, 'Authentication required.', null, [], 401);
}

$input = array_merge($_GET, $_POST);
$action = trim((string) ($input['action'] ?? ''));

if ($action === '') {
    send_json(false, 'Action parameter is required.', null, [], 400);
}

$db = Database::getConnection();

// Helper to calculate date range boundaries
function resolve_date_range(string $preset, string $customFrom, string $customTo): array
{
    $today = date('Y-m-d');

    switch ($preset) {
        case 'today':
            return [$today, $today, "Today (" . date('M j, Y') . ")"];
        case 'this_week':
            // Monday to Sunday of current week
            $monday = date('Y-m-d', strtotime('monday this week'));
            $sunday = date('Y-m-d', strtotime('sunday this week'));
            return [$monday, $sunday, "This Week (" . date('M j', strtotime($monday)) . " - " . date('M j, Y', strtotime($sunday)) . ")"];
        case 'this_month':
            $start = date('Y-m-01');
            $end = date('Y-m-t');
            return [$start, $end, "This Month (" . date('F Y') . ")"];
        case 'this_year':
            $start = date('Y-01-01');
            $end = date('Y-12-31');
            return [$start, $end, "This Year (" . date('Y') . ")"];
        case 'custom':
        default:
            $from = ($customFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom)) ? $customFrom : date('Y-m-01');
            $to = ($customTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo)) ? $customTo : $today;
            if ($from > $to) {
                $temp = $from;
                $from = $to;
                $to = $temp;
            }
            return [$from, $to, "Custom Range (" . date('M j, Y', strtotime($from)) . " - " . date('M j, Y', strtotime($to)) . ")"];
    }
}

try {
    switch ($action) {
        // ======================================================================
        // ACTION: financial_summary
        // ======================================================================
        case 'financial_summary':
            if (!has_permission('reports_view')) {
                send_json(false, 'Forbidden: You do not have permission to view financial reports.', null, [], 403);
            }

            $preset = trim((string) ($input['range'] ?? 'this_month'));
            $customFrom = trim((string) ($input['date_from'] ?? ''));
            $customTo = trim((string) ($input['date_to'] ?? ''));

            [$dateFrom, $dateTo, $rangeLabel] = resolve_date_range($preset, $customFrom, $customTo);

            // 1. Realized Gross Sales: Delivered orders in range
            $stmtSales = $db->prepare("
                SELECT 
                    COUNT(*) as delivered_orders_count,
                    COALESCE(SUM(total_amount), 0.00) as gross_sales,
                    COALESCE(SUM(subtotal), 0.00) as items_subtotal,
                    COALESCE(SUM(discount_amount), 0.00) as total_discounts,
                    COALESCE(SUM(delivery_fee), 0.00) as total_delivery_fees
                FROM orders 
                WHERE order_status = 'DELIVERED' 
                  AND DATE(COALESCE(delivered_at, created_at)) >= :date_from 
                  AND DATE(COALESCE(delivered_at, created_at)) <= :date_to
            ");
            $stmtSales->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
            $salesMetrics = $stmtSales->fetch() ?: [];

            $grossSales = (float) ($salesMetrics['gross_sales'] ?? 0.00);
            $deliveredOrdersCount = (int) ($salesMetrics['delivered_orders_count'] ?? 0);

            // 2. Operating Expenses: Active expenses (deleted_at IS NULL) in range
            $stmtExp = $db->prepare("
                SELECT 
                    COUNT(*) as expenses_count,
                    COALESCE(SUM(amount), 0.00) as total_expenses
                FROM expenses 
                WHERE deleted_at IS NULL 
                  AND expense_date >= :date_from 
                  AND expense_date <= :date_to
            ");
            $stmtExp->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
            $expMetrics = $stmtExp->fetch() ?: [];

            $totalExpenses = (float) ($expMetrics['total_expenses'] ?? 0.00);
            $expensesCount = (int) ($expMetrics['expenses_count'] ?? 0);

            // 3. Net Income = Realized Gross Sales - Operating Expenses
            $netIncome = round($grossSales - $totalExpenses, 2);
            $netMargin = ($grossSales > 0.00) ? round(($netIncome / $grossSales) * 100, 1) : 0.0;

            // 4. COD Payment States in range
            $stmtCod = $db->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN cp.status = 'REMITTED' THEN cp.amount_due ELSE 0.00 END), 0.00) as cash_remitted,
                    COALESCE(SUM(CASE WHEN cp.status = 'COLLECTED' THEN cp.amount_due ELSE 0.00 END), 0.00) as unremitted_cash,
                    COALESCE(SUM(CASE WHEN cp.status = 'PENDING' THEN cp.amount_due ELSE 0.00 END), 0.00) as pending_collection
                FROM cod_payments cp
                INNER JOIN orders o ON cp.order_id = o.id
                WHERE DATE(o.created_at) >= :date_from AND DATE(o.created_at) <= :date_to
            ");
            $stmtCod->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
            $codMetrics = $stmtCod->fetch() ?: [];

            // 5. Daily Sales Aggregation
            $stmtDailySales = $db->prepare("
                SELECT 
                    DATE(COALESCE(delivered_at, created_at)) as `date`,
                    COUNT(*) as order_count,
                    COALESCE(SUM(total_amount), 0.00) as sales_amount
                FROM orders 
                WHERE order_status = 'DELIVERED' 
                  AND DATE(COALESCE(delivered_at, created_at)) >= :date_from 
                  AND DATE(COALESCE(delivered_at, created_at)) <= :date_to
                GROUP BY DATE(COALESCE(delivered_at, created_at))
                ORDER BY `date` ASC
            ");
            $stmtDailySales->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
            $dailySalesRows = $stmtDailySales->fetchAll();

            // 6. Expenses Breakdown by Category
            $stmtCatExp = $db->prepare("
                SELECT 
                    c.name as category_name,
                    COUNT(e.id) as entry_count,
                    COALESCE(SUM(e.amount), 0.00) as category_total
                FROM expense_categories c
                LEFT JOIN expenses e ON e.category_id = c.id 
                    AND e.deleted_at IS NULL 
                    AND e.expense_date >= :date_from 
                    AND e.expense_date <= :date_to
                GROUP BY c.id, c.name
                HAVING category_total > 0
                ORDER BY category_total DESC
            ");
            $stmtCatExp->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
            $categoryRows = $stmtCatExp->fetchAll();

            $categoryBreakdown = [];
            foreach ($categoryRows as $cat) {
                $amt = (float) $cat['category_total'];
                $pct = ($totalExpenses > 0) ? round(($amt / $totalExpenses) * 100, 1) : 0.0;
                $categoryBreakdown[] = [
                    'category_name'   => $cat['category_name'],
                    'entry_count'     => (int) $cat['entry_count'],
                    'category_total'  => $amt,
                    'percentage'      => $pct
                ];
            }

            // 7. Daily Combined Cash Flow Timeline
            $dailySalesMap = [];
            foreach ($dailySalesRows as $row) {
                $dailySalesMap[$row['date']] = (float) $row['sales_amount'];
            }

            $stmtDailyExp = $db->prepare("
                SELECT 
                    expense_date as `date`,
                    COALESCE(SUM(amount), 0.00) as expense_amount
                FROM expenses 
                WHERE deleted_at IS NULL 
                  AND expense_date >= :date_from 
                  AND expense_date <= :date_to
                GROUP BY expense_date
                ORDER BY `date` ASC
            ");
            $stmtDailyExp->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
            $dailyExpRows = $stmtDailyExp->fetchAll();

            $dailyExpMap = [];
            foreach ($dailyExpRows as $row) {
                $dailyExpMap[$row['date']] = (float) $row['expense_amount'];
            }

            // Build continuous daily sequence for periods up to 35 days (e.g. This Month, Last 30 Days, This Week)
            $startTs = strtotime($dateFrom);
            $endTs = strtotime($dateTo);
            $daysDiff = (int) round(($endTs - $startTs) / 86400);

            $todayTs = strtotime(date('Y-m-d'));
            $maxTs = min($endTs, $todayTs);
            if ($maxTs < $startTs) $maxTs = $startTs;

            if ($daysDiff <= 35) {
                $allDates = [];
                $curr = $startTs;
                while ($curr <= $maxTs) {
                    $allDates[] = date('Y-m-d', $curr);
                    $curr = strtotime('+1 day', $curr);
                }
                // Ensure at least 2 points so ApexCharts can always draw continuous lines
                if (count($allDates) === 1) {
                    $prev = date('Y-m-d', strtotime('-1 day', strtotime($allDates[0])));
                    array_unshift($allDates, $prev);
                }
            } else {
                $allDates = array_unique(array_merge(array_keys($dailySalesMap), array_keys($dailyExpMap)));
                sort($allDates);
                if (count($allDates) === 1) {
                    $prev = date('Y-m-d', strtotime('-1 day', strtotime($allDates[0])));
                    array_unshift($allDates, $prev);
                }
            }

            $cashFlowTimeline = [];
            foreach ($allDates as $dt) {
                $s = $dailySalesMap[$dt] ?? 0.00;
                $e = $dailyExpMap[$dt] ?? 0.00;
                $cashFlowTimeline[] = [
                    'date'     => $dt,
                    'sales'    => $s,
                    'expenses' => $e,
                    'net'      => round($s - $e, 2)
                ];
            }

            send_json(true, 'Financial summary calculated successfully.', [
                'range' => [
                    'preset'    => $preset,
                    'date_from' => $dateFrom,
                    'date_to'   => $dateTo,
                    'label'     => $rangeLabel
                ],
                'summary' => [
                    'gross_sales'            => $grossSales,
                    'delivered_orders_count' => $deliveredOrdersCount,
                    'operating_expenses'     => $totalExpenses,
                    'expenses_count'         => $expensesCount,
                    'net_income'             => $netIncome,
                    'net_margin_percentage'  => $netMargin,
                    'cash_remitted'          => (float) ($codMetrics['cash_remitted'] ?? 0.00),
                    'unremitted_cod_cash'    => (float) ($codMetrics['unremitted_cash'] ?? 0.00),
                    'pending_cod_potential'  => (float) ($codMetrics['pending_collection'] ?? 0.00),
                    'zero_tax_verified'      => true
                ],
                'categories' => $categoryBreakdown,
                'timeline'   => $cashFlowTimeline
            ]);
            break;

        // ======================================================================
        // ACTION: export_csv (Low-Memory Streaming)
        // ======================================================================
        case 'export_csv':
            if (!has_permission('reports_export')) {
                send_json(false, 'Forbidden: You do not have permission to export financial reports.', null, [], 403);
            }

            $preset = trim((string) ($input['range'] ?? 'this_month'));
            $customFrom = trim((string) ($input['date_from'] ?? ''));
            $customTo = trim((string) ($input['date_to'] ?? ''));

            [$dateFrom, $dateTo, $rangeLabel] = resolve_date_range($preset, $customFrom, $customTo);

            // Fetch Realized Sales records
            $stmtSales = $db->prepare("
                SELECT 
                    COALESCE(DATE(delivered_at), DATE(created_at)) as trans_date,
                    'SALE' as record_type,
                    order_number as ref_no,
                    customer_name as entity_name,
                    payment_method,
                    order_status as status_label,
                    total_amount as inflow_amount,
                    0.00 as outflow_amount
                FROM orders 
                WHERE order_status = 'DELIVERED' 
                  AND DATE(COALESCE(delivered_at, created_at)) >= :date_from 
                  AND DATE(COALESCE(delivered_at, created_at)) <= :date_to
            ");
            $stmtSales->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
            $salesRows = $stmtSales->fetchAll();

            // Fetch Operating Expenses records
            $stmtExp = $db->prepare("
                SELECT 
                    e.expense_date as trans_date,
                    'EXPENSE' as record_type,
                    CONCAT('EXP-', LPAD(e.id, 5, '0')) as ref_no,
                    CONCAT(c.name, ' - ', e.title) as entity_name,
                    'CASH/DIRECT' as payment_method,
                    'RECORDED' as status_label,
                    0.00 as inflow_amount,
                    e.amount as outflow_amount
                FROM expenses e
                JOIN expense_categories c ON e.category_id = c.id
                WHERE e.deleted_at IS NULL 
                  AND e.expense_date >= :date_from 
                  AND e.expense_date <= :date_to
            ");
            $stmtExp->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
            $expRows = $stmtExp->fetchAll();

            // Merge and sort chronologically by date ASC
            $transactions = array_merge($salesRows, $expRows);
            usort($transactions, function ($a, $b) {
                return strcmp($a['trans_date'], $b['trans_date']);
            });

            // Clean output buffer before streaming CSV
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $filename = sprintf('financial-report-%s-to-%s.csv', $dateFrom, $dateTo);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            // Open streaming output handle
            $output = fopen('php://output', 'w');

            // UTF-8 BOM for Microsoft Excel compatibility
            fwrite($output, "\xEF\xBB\xBF");

            // CSV Title Metadata
            fputcsv($output, [APP_NAME . ' — Financial Performance Report']);
            fputcsv($output, ['Period: ' . $rangeLabel, 'Exported: ' . date('Y-m-d H:i:s')]);
            fputcsv($output, ['Currency: ' . APP_CURRENCY . ' (PHP)', 'Tax Model: Strictly Zero-Tax / Tax-Exempt']);
            fputcsv($output, []); // Blank separator row

            // Table Column Headers
            fputcsv($output, [
                'Date',
                'Type',
                'Reference No',
                'Description / Customer',
                'Payment Method',
                'Status',
                'Inflow (Sales)',
                'Outflow (Expenses)',
                'Net Cash Flow'
            ]);

            $totalInflow = 0.00;
            $totalOutflow = 0.00;

            foreach ($transactions as $tx) {
                $inflow = (float) $tx['inflow_amount'];
                $outflow = (float) $tx['outflow_amount'];
                $net = round($inflow - $outflow, 2);

                $totalInflow += $inflow;
                $totalOutflow += $outflow;

                fputcsv($output, [
                    $tx['trans_date'],
                    $tx['record_type'],
                    $tx['ref_no'],
                    $tx['entity_name'],
                    $tx['payment_method'],
                    $tx['status_label'],
                    number_format($inflow, 2, '.', ''),
                    number_format($outflow, 2, '.', ''),
                    number_format($net, 2, '.', '')
                ]);
            }

            $overallNet = round($totalInflow - $totalOutflow, 2);

            // Summary Footer Rows
            fputcsv($output, []);
            fputcsv($output, [
                'TOTALS',
                '',
                '',
                '',
                '',
                '',
                number_format($totalInflow, 2, '.', ''),
                number_format($totalOutflow, 2, '.', ''),
                number_format($overallNet, 2, '.', '')
            ]);
            fputcsv($output, [
                'NET INCOME SUMMARY',
                'Realized Gross Sales: ' . number_format($totalInflow, 2, '.', ''),
                'Operating Expenses: ' . number_format($totalOutflow, 2, '.', ''),
                'Net Income: ' . number_format($overallNet, 2, '.', '')
            ]);

            fclose($output);

            audit_log('reports', 'EXPORT_CSV', null, null, [
                'date_from'     => $dateFrom,
                'date_to'       => $dateTo,
                'total_inflow'  => $totalInflow,
                'total_outflow' => $totalOutflow,
                'net_income'    => $overallNet
            ]);

            exit;

        default:
            send_json(false, "Unknown action '{$action}'.", null, [], 400);
            break;
    }
} catch (PDOException $e) {
    error_log('[Reports Handler PDO Error] ' . $e->getMessage());
    send_json(false, 'A database error occurred while calculating financial reports.', null, [
        'error' => 'Database operation failed.'
    ], 500);
} catch (Throwable $e) {
    error_log('[Reports Handler Error] ' . $e->getMessage());
    send_json(false, 'An unexpected server error occurred.', null, [
        'error' => 'Internal server error.'
    ], 500);
}

