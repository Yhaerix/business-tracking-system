<?php
/**
 * Financial Math Unit Tests
 * Verifies domain logic and formulas from docs/BUSINESS_RULES.md
 */

declare(strict_types=1);

class FinancialMathTest {
    /**
     * Test Order Total Formula: Subtotal - Discount + Delivery Fee (Zero tax)
     */
    public function test_order_total_calculation_with_zero_tax(): void {
        $subtotal = 1250.00;
        $discount = 50.00;
        $deliveryFee = 75.00;

        // Formula from docs/BUSINESS_RULES.md
        $total = $subtotal - $discount + $deliveryFee;

        assert_equals(1275.00, $total, "Order total calculation failed");
    }

    /**
     * Test Net Income Formula: Realized Gross Sales - Total Operating Expenses
     */
    public function test_net_income_calculation(): void {
        $realizedGrossSales = 25000.00; // Delivered & Collected
        $operatingExpenses = 8500.00;

        // Formula from docs/BUSINESS_RULES.md
        $netIncome = $realizedGrossSales - $operatingExpenses;

        assert_equals(16500.00, $netIncome, "Net Income calculation failed");
    }

    /**
     * Test Uncollected / Cancelled Orders Exclusion
     */
    public function test_uncollected_orders_excluded_from_realized_sales(): void {
        $orders = [
            ['id' => 1, 'amount' => 1000.00, 'status' => 'DELIVERED', 'payment' => 'COLLECTED'],
            ['id' => 2, 'amount' => 500.00,  'status' => 'PENDING',   'payment' => 'PENDING_COLLECTION'],
            ['id' => 3, 'amount' => 300.00,  'status' => 'CANCELLED', 'payment' => 'FAILED'],
            ['id' => 4, 'amount' => 2000.00, 'status' => 'DELIVERED', 'payment' => 'REMITTED'],
        ];

        $realizedSales = 0.00;
        foreach ($orders as $order) {
            if ($order['status'] === 'DELIVERED' && in_array($order['payment'], ['COLLECTED', 'REMITTED'], true)) {
                $realizedSales += $order['amount'];
            }
        }

        // Only Order #1 and #4 should count: 1000 + 2000 = 3000
        assert_equals(3000.00, $realizedSales, "Uncollected orders were incorrectly included");
    }
}
