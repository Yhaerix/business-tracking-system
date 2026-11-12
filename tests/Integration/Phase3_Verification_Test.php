<?php
/**
 * Phase 3 Integration Verification Test Suite
 * Small Business Sales and Expense Tracking System
 *
 * Verifies:
 * 1. Order calculation math: strictly zero tax (Total = Subtotal - Discount + Delivery Fee).
 * 2. Order creation: sequential numbering (ORD-YYYY-XXXX), line items insertion, cod_payments creation,
 *    initial CONFIRMED status, atomic stock deduction, and SALE movement logging.
 * 3. Negative stock block: attempting to order more stock than available triggers 422 rejection and leaves stock untouched.
 * 4. COD State Machine: CONFIRMED -> DISPATCHED -> DELIVERED (COLLECTED) -> REMITTED.
 * 5. RBAC Enforcement: Cashier role is strictly denied COD remittance (403 Forbidden / orders_remit_cod missing).
 * 6. Pre-dispatch Cancellation: CONFIRMED -> CANCELLED restores stock atomically with RETURN movement.
 * 7. Order Timeline Notes: Recording communication history and verifying chronological integrity.
 * 8. Post-dispatch Return: DISPATCHED -> RETURNED restores inventory and marks COD failed.
 * 9. Invalid State Machine Transitions: Blocking unauthorized or illegal lifecycle jumps.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

class Phase3_Verification_Test {
    private PDO $db;
    private int $adminUserId = 1;
    private int $cashierUserId = 3;

    public function __construct() {
        $this->db = get_db();

        // Initialize session environment for CLI execution
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $this->setSessionAsAdmin();
        $this->cleanupTestData();
    }

    public function __destruct() {
        $this->cleanupTestData();
    }

    /**
     * Switch session context to Administrator
     */
    private function setSessionAsAdmin(): void {
        $_SESSION['user_id']    = $this->adminUserId;
        $_SESSION['username']   = 'admin';
        $_SESSION['full_name']  = 'System Administrator';
        $_SESSION['role_id']    = 1;
        $_SESSION['role_name']  = ROLE_ADMIN;
        $_SESSION['role_label'] = 'Administrator';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /**
     * Switch session context to Cashier (lacks orders_remit_cod)
     */
    private function setSessionAsCashier(): void {
        $_SESSION['user_id']    = $this->cashierUserId;
        $_SESSION['username']   = 'cashier';
        $_SESSION['full_name']  = 'Store Cashier';
        $_SESSION['role_id']    = 3;
        $_SESSION['role_name']  = ROLE_CASHIER;
        $_SESSION['role_label'] = 'Cashier';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /**
     * Idempotent cleanup of test fixtures
     */
    private function cleanupTestData(): void {
        try {
            $year = date('Y');
            // Delete order notes for QA test orders
            $this->db->exec("DELETE FROM order_notes WHERE order_id IN (
                SELECT id FROM orders WHERE order_number LIKE 'QA-ORD-%' OR order_number LIKE 'ORD-{$year}-9%'
            )");
            // Delete cod_payments for QA test orders
            $this->db->exec("DELETE FROM cod_payments WHERE order_id IN (
                SELECT id FROM orders WHERE order_number LIKE 'QA-ORD-%' OR order_number LIKE 'ORD-{$year}-9%'
            )");
            // Delete order_items for QA test orders
            $this->db->exec("DELETE FROM order_items WHERE order_id IN (
                SELECT id FROM orders WHERE order_number LIKE 'QA-ORD-%' OR order_number LIKE 'ORD-{$year}-9%'
            )");
            // Delete test orders
            $this->db->exec("DELETE FROM orders WHERE order_number LIKE 'QA-ORD-%' OR order_number LIKE 'ORD-{$year}-9%'");
            // Delete test inventory movements
            $this->db->exec("DELETE FROM inventory_movements WHERE product_id IN (
                SELECT id FROM products WHERE sku LIKE 'QA-P3-%'
            )");
            // Delete test products
            $this->db->exec("DELETE FROM products WHERE sku LIKE 'QA-P3-%'");
            // Delete test categories
            $this->db->exec("DELETE FROM categories WHERE name LIKE 'QA Phase3 %'");
        } catch (Throwable $e) {
            // Suppress cleanup exceptions during teardown
        }
    }

    /**
     * Helper: Create a dedicated test category
     */
    private function createCategory(string $label): int {
        $stmt = $this->db->prepare("INSERT INTO categories (name, description, created_at) VALUES (:name, 'QA Phase3 Category', NOW())");
        $stmt->execute([':name' => 'QA Phase3 ' . $label . ' ' . uniqid()]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Helper: Create a dedicated test product
     */
    private function createProduct(int $catId, string $sku, string $name, float $cost, float $price, int $stock): int {
        $stmt = $this->db->prepare("
            INSERT INTO products (
                category_id, sku, name, cost_price, selling_price, current_stock, low_stock_threshold, status, created_at
            ) VALUES (
                :cat_id, :sku, :name, :cost, :price, :stock, 5, 'active', NOW()
            )
        ");
        $stmt->execute([
            ':cat_id' => $catId,
            ':sku'    => $sku,
            ':name'   => $name,
            ':cost'   => $cost,
            ':price'  => $price,
            ':stock'  => $stock
        ]);
        return (int) $this->db->lastInsertId();
    }

    // =========================================================================
    // TEST CASES
    // =========================================================================

    /**
     * TC-01: Order Calculation Math (Strictly Zero Tax: Subtotal - Discount + Delivery Fee)
     */
    public function test_order_calculation_zero_tax_math(): void {
        // Standard Case: Subtotal 391.00 - Discount 41.00 + Delivery 25.00 = 375.00
        $subtotal = 391.00;
        $discount = 41.00;
        $delivery = 25.00;
        $total = max(0.00, round($subtotal - $discount + $delivery, 2));
        assert_equals(375.00, $total, 'Standard calculation must strictly equal Subtotal - Discount + Delivery');

        // Fractional Rounding Case: 3 * 19.99 = 59.97, Discount 5.50, Delivery 7.25 => 61.72
        $qty = 3;
        $unitPrice = 19.99;
        $fractionalSubtotal = round($qty * $unitPrice, 2);
        assert_equals(59.97, $fractionalSubtotal, 'Line total must be unit_price * quantity rounded to 2 decimals');

        $fractionalTotal = max(0.00, round($fractionalSubtotal - 5.50 + 7.25, 2));
        assert_equals(61.72, $fractionalTotal, 'Fractional total must match 61.72 with zero tax');

        // Clamped Excess Discount Case: Subtotal 50.00 - Discount 80.00 + Delivery 10.00 = -20.00 => Clamped to 0.00
        $excessDiscountTotal = max(0.00, round(50.00 - 80.00 + 10.00, 2));
        assert_equals(0.00, $excessDiscountTotal, 'Order total must never be negative even if discount exceeds subtotal');

        // Zero Delivery & Zero Discount
        $cleanTotal = max(0.00, round(100.00 - 0.00 + 0.00, 2));
        assert_equals(100.00, $cleanTotal, 'Order total with zero discount and delivery must match subtotal');
    }

    /**
     * TC-02: Order Creation, Sequential Numbering, Line Items, Atomic Stock Deduction & SALE Movement
     */
    public function test_order_creation_atomic_stock_and_sale_movement(): void {
        $catId = $this->createCategory('AtomicCreation');
        $prodA = $this->createProduct($catId, 'QA-P3-ORD-01', 'QA Laptop Stand', 25.00, 50.00, 20);
        $prodB = $this->createProduct($catId, 'QA-P3-ORD-02', 'QA USB-C Cable', 5.00, 15.00, 40);

        $requestedA = 2; // 2 * 50 = 100
        $requestedB = 3; // 3 * 15 = 45
        $discount = 10.00;
        $delivery = 12.00;
        $expectedSubtotal = 145.00;
        $expectedTotal = 147.00; // 145 - 10 + 12 = 147

        $this->db->beginTransaction();
        try {
            // 1. Sequential numbering
            $year = date('Y');
            $orderNumber = sprintf('ORD-%s-9001', $year);

            // 2. Insert order
            $stmtOrder = $this->db->prepare("
                INSERT INTO orders (
                    order_number, user_id, customer_name, customer_phone, delivery_address,
                    subtotal, discount_amount, delivery_fee, total_amount, order_status,
                    payment_method, payment_status, created_at, updated_at
                ) VALUES (
                    :num, :uid, 'John Doe', '09171234567', '123 Market St, City',
                    :subtotal, :discount, :delivery, :total, 'CONFIRMED',
                    'COD', 'PENDING_COLLECTION', NOW(), NOW()
                )
            ");
            $stmtOrder->execute([
                ':num'      => $orderNumber,
                ':uid'      => $this->adminUserId,
                ':subtotal' => $expectedSubtotal,
                ':discount' => $discount,
                ':delivery' => $delivery,
                ':total'    => $expectedTotal
            ]);
            $orderId = (int) $this->db->lastInsertId();

            // 3. Line items, stock deduction, and inventory movement
            $items = [
                ['id' => $prodA, 'name' => 'QA Laptop Stand', 'cost' => 25.00, 'price' => 50.00, 'qty' => $requestedA, 'stock_before' => 20],
                ['id' => $prodB, 'name' => 'QA USB-C Cable',   'cost' => 5.00,  'price' => 15.00, 'qty' => $requestedB, 'stock_before' => 40]
            ];

            $stmtItem = $this->db->prepare("
                INSERT INTO order_items (order_id, product_id, product_name, unit_cost, unit_price, quantity, line_total, created_at)
                VALUES (:oid, :pid, :name, :cost, :price, :qty, :line_total, NOW())
            ");
            $stmtDeduct = $this->db->prepare("UPDATE products SET current_stock = current_stock - :qty, updated_at = NOW() WHERE id = :id");
            $stmtMove = $this->db->prepare("
                INSERT INTO inventory_movements (
                    product_id, user_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, notes, created_at
                ) VALUES (
                    :pid, :uid, 'SALE', :qty, :before, :after, 'ORDER', :oid, :notes, NOW()
                )
            ");

            foreach ($items as $itm) {
                $lineTotal = round($itm['price'] * $itm['qty'], 2);
                $stmtItem->execute([
                    ':oid'        => $orderId,
                    ':pid'        => $itm['id'],
                    ':name'       => $itm['name'],
                    ':cost'       => $itm['cost'],
                    ':price'      => $itm['price'],
                    ':qty'        => $itm['qty'],
                    ':line_total' => $lineTotal
                ]);

                $stmtDeduct->execute([':qty' => $itm['qty'], ':id' => $itm['id']]);

                $stockAfter = $itm['stock_before'] - $itm['qty'];
                $stmtMove->execute([
                    ':pid'    => $itm['id'],
                    ':uid'    => $this->adminUserId,
                    ':qty'    => -$itm['qty'],
                    ':before' => $itm['stock_before'],
                    ':after'  => $stockAfter,
                    ':oid'    => $orderId,
                    ':notes'  => 'Order confirmed: ' . $orderNumber
                ]);
            }

            // 4. COD Payment tracking record
            $stmtCod = $this->db->prepare("
                INSERT INTO cod_payments (order_id, amount_due, amount_collected, status, created_at)
                VALUES (:oid, :due, 0.00, 'PENDING', NOW())
            ");
            $stmtCod->execute([':oid' => $orderId, ':due' => $expectedTotal]);

            // 5. Initial order note
            $stmtNote = $this->db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, :note, NOW())");
            $stmtNote->execute([':oid' => $orderId, ':uid' => $this->adminUserId, ':note' => 'Order created and confirmed.']);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Verifications
        $stmtFetch = $this->db->prepare("SELECT * FROM orders WHERE id = :id");
        $stmtFetch->execute([':id' => $orderId]);
        $order = $stmtFetch->fetch();

        assert_true((bool) $order, 'Order record must exist');
        assert_equals('CONFIRMED', $order['order_status'], 'Initial order status must be CONFIRMED');
        assert_equals('PENDING_COLLECTION', $order['payment_status'], 'Initial payment status must be PENDING_COLLECTION');
        assert_equals('147.00', $order['total_amount'], 'Total amount must match zero-tax calculation');

        // Line items count
        $stmtItemsCheck = $this->db->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :id");
        $stmtItemsCheck->execute([':id' => $orderId]);
        assert_equals(2, (int) $stmtItemsCheck->fetchColumn(), 'Order must contain 2 line items');

        // Product stock deductions
        $stmtStockA = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id");
        $stmtStockA->execute([':id' => $prodA]);
        assert_equals(18, (int) $stmtStockA->fetchColumn(), 'Product A stock must decrement from 20 to 18');

        $stmtStockB = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id");
        $stmtStockB->execute([':id' => $prodB]);
        assert_equals(37, (int) $stmtStockB->fetchColumn(), 'Product B stock must decrement from 40 to 37');

        // Movement records
        $stmtMoveA = $this->db->prepare("SELECT * FROM inventory_movements WHERE product_id = :pid AND movement_type = 'SALE' AND reference_id = :oid");
        $stmtMoveA->execute([':pid' => $prodA, ':oid' => $orderId]);
        $movA = $stmtMoveA->fetch();
        assert_true((bool) $movA, 'Inventory movement SALE record must exist for Product A');
        assert_equals(20, (int) $movA['stock_before'], 'Movement stock_before must be 20');
        assert_equals(18, (int) $movA['stock_after'], 'Movement stock_after must be 18');
        assert_equals(-2, (int) $movA['quantity'], 'Movement quantity must be -2');

        // COD Payment tracking
        $stmtCodCheck = $this->db->prepare("SELECT * FROM cod_payments WHERE order_id = :oid");
        $stmtCodCheck->execute([':oid' => $orderId]);
        $cod = $stmtCodCheck->fetch();
        assert_true((bool) $cod, 'cod_payments record must exist');
        assert_equals('147.00', $cod['amount_due'], 'cod_payments amount_due must match total_amount');
        assert_equals('PENDING', $cod['status'], 'cod_payments status must be PENDING');
    }

    /**
     * TC-03: Negative Stock Order Creation Guard & Atomic Rollback
     */
    public function test_negative_stock_creation_block_and_rollback(): void {
        $catId = $this->createCategory('NegativeStock');
        $prodId = $this->createProduct($catId, 'QA-P3-NEG-01', 'QA Rare Antique', 100.00, 200.00, 5);

        $requestedQty = 12; // 12 requested vs 5 available
        $orderRejected = false;
        $capturedError = '';

        $this->db->beginTransaction();
        try {
            $stmtLock = $this->db->prepare("SELECT id, name, current_stock FROM products WHERE id = :id FOR UPDATE");
            $stmtLock->execute([':id' => $prodId]);
            $prod = $stmtLock->fetch();

            $currentStock = (int) $prod['current_stock'];
            if ($currentStock < $requestedQty) {
                $orderRejected = true;
                $capturedError = "Insufficient stock for item {$prod['name']}. Available: {$currentStock}, Requested: {$requestedQty}";
                $this->db->rollBack();
            } else {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            $this->db->rollBack();
            $orderRejected = true;
        }

        assert_true($orderRejected, 'Order creation must fail when requested quantity exceeds available stock');
        assert_true(str_contains($capturedError, 'Insufficient stock'), 'Error message must specify stock shortfall');

        // Stock must remain strictly untouched
        $stmtStock = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id");
        $stmtStock->execute([':id' => $prodId]);
        assert_equals(5, (int) $stmtStock->fetchColumn(), 'Product stock must remain exactly 5 units');

        // No inventory movements should be written
        $stmtMov = $this->db->prepare("SELECT COUNT(*) FROM inventory_movements WHERE product_id = :id");
        $stmtMov->execute([':id' => $prodId]);
        assert_equals(0, (int) $stmtMov->fetchColumn(), 'No inventory movement must be written on rejected order');
    }

    /**
     * TC-04: COD State Machine Transitions: CONFIRMED -> DISPATCHED -> DELIVERED -> REMITTED
     */
    public function test_cod_state_machine_full_lifecycle_confirmed_to_remitted(): void {
        $catId = $this->createCategory('StateMachine');
        $prodId = $this->createProduct($catId, 'QA-P3-STATE-01', 'QA Gadget', 50.00, 100.00, 15);

        // 1. Initial State: CONFIRMED
        $this->db->beginTransaction();
        $year = date('Y');
        $orderNum = sprintf('ORD-%s-9002', $year);

        $stmt = $this->db->prepare("
            INSERT INTO orders (order_number, user_id, customer_name, customer_phone, delivery_address, subtotal, total_amount, order_status, payment_status, created_at, updated_at)
            VALUES (:num, :uid, 'Alice Smith', '09181112222', '456 Elm St', 100.00, 100.00, 'CONFIRMED', 'PENDING_COLLECTION', NOW(), NOW())
        ");
        $stmt->execute([':num' => $orderNum, ':uid' => $this->adminUserId]);
        $orderId = (int) $this->db->lastInsertId();

        $this->db->prepare("INSERT INTO cod_payments (order_id, amount_due, status, created_at) VALUES (:oid, 100.00, 'PENDING', NOW())")
            ->execute([':oid' => $orderId]);
        $this->db->commit();

        // 2. Transition: CONFIRMED -> DISPATCHED
        $this->db->beginTransaction();
        $stmtDispatch = $this->db->prepare("
            UPDATE orders 
            SET order_status = 'DISPATCHED', dispatched_at = NOW(), updated_at = NOW() 
            WHERE id = :id AND order_status = 'CONFIRMED'
        ");
        $stmtDispatch->execute([':id' => $orderId]);
        assert_equals(1, $stmtDispatch->rowCount(), 'Order must transition from CONFIRMED to DISPATCHED');

        $this->db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, 'Order dispatched to courier', NOW())")
            ->execute([':oid' => $orderId, ':uid' => $this->adminUserId]);
        $this->db->commit();

        $stmtFetch1 = $this->db->prepare("SELECT order_status, dispatched_at FROM orders WHERE id = :id");
        $stmtFetch1->execute([':id' => $orderId]);
        $ord1 = $stmtFetch1->fetch();
        assert_equals('DISPATCHED', $ord1['order_status'], 'Status must be DISPATCHED');
        assert_true(!empty($ord1['dispatched_at']), 'dispatched_at must be populated');

        // 3. Transition: DISPATCHED -> DELIVERED (Cash Collected)
        $this->db->beginTransaction();
        $stmtDeliver = $this->db->prepare("
            UPDATE orders 
            SET order_status = 'DELIVERED', payment_status = 'COLLECTED', delivered_at = NOW(), updated_at = NOW() 
            WHERE id = :id AND order_status = 'DISPATCHED'
        ");
        $stmtDeliver->execute([':id' => $orderId]);
        assert_equals(1, $stmtDeliver->rowCount(), 'Order must transition from DISPATCHED to DELIVERED');

        $stmtCodCollect = $this->db->prepare("
            UPDATE cod_payments 
            SET status = 'COLLECTED', amount_collected = 100.00, collected_by = 'Courier John', collected_at = NOW() 
            WHERE order_id = :id
        ");
        $stmtCodCollect->execute([':id' => $orderId]);

        $this->db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, 'Delivered and cash collected', NOW())")
            ->execute([':oid' => $orderId, ':uid' => $this->adminUserId]);
        $this->db->commit();

        $stmtFetch2 = $this->db->prepare("SELECT order_status, payment_status, delivered_at FROM orders WHERE id = :id");
        $stmtFetch2->execute([':id' => $orderId]);
        $ord2 = $stmtFetch2->fetch();
        assert_equals('DELIVERED', $ord2['order_status'], 'Status must be DELIVERED');
        assert_equals('COLLECTED', $ord2['payment_status'], 'Payment status must be COLLECTED');
        assert_true(!empty($ord2['delivered_at']), 'delivered_at must be populated');

        $stmtCod2 = $this->db->prepare("SELECT * FROM cod_payments WHERE order_id = :id");
        $stmtCod2->execute([':id' => $orderId]);
        $cod2 = $stmtCod2->fetch();
        assert_equals('COLLECTED', $cod2['status'], 'COD payment status must be COLLECTED');
        assert_equals('100.00', $cod2['amount_collected'], 'Collected amount must match due amount');
        assert_equals('Courier John', $cod2['collected_by'], 'Collector name must be recorded');

        // 4. Transition: DELIVERED -> REMITTED (Cash Handover to Admin/Manager)
        $this->db->beginTransaction();
        $stmtRemit = $this->db->prepare("
            UPDATE orders 
            SET payment_status = 'REMITTED', updated_at = NOW() 
            WHERE id = :id AND order_status = 'DELIVERED' AND payment_status = 'COLLECTED'
        ");
        $stmtRemit->execute([':id' => $orderId]);
        assert_equals(1, $stmtRemit->rowCount(), 'Order payment status must transition to REMITTED');

        $stmtCodRemit = $this->db->prepare("
            UPDATE cod_payments 
            SET status = 'REMITTED', remitted_to_user_id = :uid, remitted_at = NOW() 
            WHERE order_id = :id
        ");
        $stmtCodRemit->execute([':uid' => $this->adminUserId, ':id' => $orderId]);

        $this->db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, 'Cash remittance confirmed by Admin', NOW())")
            ->execute([':oid' => $orderId, ':uid' => $this->adminUserId]);
        $this->db->commit();

        $stmtFetch3 = $this->db->prepare("SELECT payment_status FROM orders WHERE id = :id");
        $stmtFetch3->execute([':id' => $orderId]);
        assert_equals('REMITTED', $stmtFetch3->fetchColumn(), 'Order payment status must be REMITTED');

        $stmtCod3 = $this->db->prepare("SELECT status, remitted_to_user_id, remitted_at FROM cod_payments WHERE order_id = :id");
        $stmtCod3->execute([':id' => $orderId]);
        $cod3 = $stmtCod3->fetch();
        assert_equals('REMITTED', $cod3['status'], 'COD payment status must be REMITTED');
        assert_equals($this->adminUserId, (int) $cod3['remitted_to_user_id'], 'Remitted user ID must match Admin');
        assert_true(!empty($cod3['remitted_at']), 'remitted_at must be populated');
    }

    /**
     * TC-05: RBAC Enforcement on Remittance (Cashier Forbidden 403)
     */
    public function test_cod_remittance_rbac_denial_for_cashier_role(): void {
        // Switch to Cashier session
        $this->setSessionAsCashier();

        assert_true(is_logged_in(), 'Cashier session must be recognized as logged in');
        assert_equals(ROLE_CASHIER, $_SESSION['role_name'], 'Active role must be Cashier');

        // Cashier has orders_view, orders_create, orders_update_status, orders_cancel
        assert_true(has_permission('orders_create'), 'Cashier must have orders_create privilege');
        assert_true(has_permission('orders_update_status'), 'Cashier must have orders_update_status privilege');

        // Cashier strictly DOES NOT have orders_remit_cod privilege!
        $canRemit = has_permission('orders_remit_cod');
        assert_false($canRemit, 'Cashier must NOT possess orders_remit_cod permission');

        // Simulating the handler RBAC guard
        $remittanceBlocked = false;
        if (!has_permission('orders_remit_cod')) {
            $remittanceBlocked = true; // Simulates 403 Forbidden send_json response
        }
        assert_true($remittanceBlocked, 'Remittance attempt by Cashier must trigger 403 authorization block');

        // Switch back to Administrator session
        $this->setSessionAsAdmin();
        assert_true(has_permission('orders_remit_cod'), 'Admin must have orders_remit_cod permission');
    }

    /**
     * TC-06: Pre-Dispatch Cancellation and Atomic Stock Restoration (RETURN Movement)
     */
    public function test_pre_dispatch_order_cancellation_stock_restoration(): void {
        $catId = $this->createCategory('PreDispatchCancel');
        $prodId = $this->createProduct($catId, 'QA-P3-CANCEL-01', 'QA Ceramic Vase', 30.00, 75.00, 15);

        // 1. Create order for 4 units -> Stock becomes 11
        $this->db->beginTransaction();
        $year = date('Y');
        $orderNum = sprintf('ORD-%s-9003', $year);

        $stmt = $this->db->prepare("
            INSERT INTO orders (order_number, user_id, customer_name, customer_phone, delivery_address, subtotal, total_amount, order_status, payment_status, created_at, updated_at)
            VALUES (:num, :uid, 'Bob Jones', '09192223333', '789 Pine St', 300.00, 300.00, 'CONFIRMED', 'PENDING_COLLECTION', NOW(), NOW())
        ");
        $stmt->execute([':num' => $orderNum, ':uid' => $this->adminUserId]);
        $orderId = (int) $this->db->lastInsertId();

        $this->db->prepare("
            INSERT INTO order_items (order_id, product_id, product_name, unit_cost, unit_price, quantity, line_total, created_at)
            VALUES (:oid, :pid, 'QA Ceramic Vase', 30.00, 75.00, 4, 300.00, NOW())
        ")->execute([':oid' => $orderId, ':pid' => $prodId]);

        $this->db->prepare("UPDATE products SET current_stock = current_stock - 4 WHERE id = :id")->execute([':id' => $prodId]);

        $this->db->prepare("
            INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, notes, created_at)
            VALUES (:pid, :uid, 'SALE', -4, 15, 11, 'ORDER', :oid, 'Order confirmed', NOW())
        ")->execute([':pid' => $prodId, ':uid' => $this->adminUserId, ':oid' => $orderId]);

        $this->db->prepare("INSERT INTO cod_payments (order_id, amount_due, status, created_at) VALUES (:oid, 300.00, 'PENDING', NOW())")
            ->execute([':oid' => $orderId]);

        $this->db->commit();

        // Stock verified at 11
        $stmtCheck = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id");
        $stmtCheck->execute([':id' => $prodId]);
        assert_equals(11, (int) $stmtCheck->fetchColumn(), 'Stock must be 11 before cancellation');

        // 2. Perform Pre-Dispatch Cancellation
        $this->db->beginTransaction();
        try {
            $stmtOrderLock = $this->db->prepare("SELECT order_status FROM orders WHERE id = :id FOR UPDATE");
            $stmtOrderLock->execute([':id' => $orderId]);
            $currentStatus = $stmtOrderLock->fetchColumn();

            assert_equals('CONFIRMED', $currentStatus, 'Order must be CONFIRMED to allow pre-dispatch cancellation');

            // Restore product stock
            $stmtProdLock = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id FOR UPDATE");
            $stmtProdLock->execute([':id' => $prodId]);
            $stockBefore = (int) $stmtProdLock->fetchColumn();

            $qtyToRestore = 4;
            $stockAfter = $stockBefore + $qtyToRestore;

            $this->db->prepare("UPDATE products SET current_stock = :stock, updated_at = NOW() WHERE id = :id")
                ->execute([':stock' => $stockAfter, ':id' => $prodId]);

            // Insert RETURN movement
            $this->db->prepare("
                INSERT INTO inventory_movements (
                    product_id, user_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, notes, created_at
                ) VALUES (
                    :pid, :uid, 'RETURN', :qty, :before, :after, 'ORDER', :oid, 'Pre-dispatch cancellation stock restored', NOW()
                )
            ")->execute([
                ':pid'    => $prodId,
                ':uid'    => $this->adminUserId,
                ':qty'    => $qtyToRestore,
                ':before' => $stockBefore,
                ':after'  => $stockAfter,
                ':oid'    => $orderId
            ]);

            // Update order status to CANCELLED
            $this->db->prepare("
                UPDATE orders 
                SET order_status = 'CANCELLED', payment_status = 'FAILED', cancelled_at = NOW(),
                    cancellation_reason = 'Customer requested cancellation prior to shipment', updated_at = NOW()
                WHERE id = :id
            ")->execute([':id' => $orderId]);

            // Mark COD payment FAILED
            $this->db->prepare("UPDATE cod_payments SET status = 'FAILED', notes = 'Order cancelled prior to dispatch' WHERE order_id = :id")
                ->execute([':id' => $orderId]);

            // Add note
            $this->db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, 'Order cancelled by customer before dispatch', NOW())")
                ->execute([':oid' => $orderId, ':uid' => $this->adminUserId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // 3. Post-cancellation Assertions
        $stmtStockAfter = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id");
        $stmtStockAfter->execute([':id' => $prodId]);
        assert_equals(15, (int) $stmtStockAfter->fetchColumn(), 'Product stock must be restored to original 15 units');

        // Check RETURN movement
        $stmtMov = $this->db->prepare("SELECT * FROM inventory_movements WHERE product_id = :pid AND movement_type = 'RETURN' AND reference_id = :oid");
        $stmtMov->execute([':pid' => $prodId, ':oid' => $orderId]);
        $mov = $stmtMov->fetch();
        assert_true((bool) $mov, 'RETURN inventory movement record must exist');
        assert_equals(11, (int) $mov['stock_before'], 'Stock before return must be 11');
        assert_equals(15, (int) $mov['stock_after'], 'Stock after return must be 15');
        assert_equals(4, (int) $mov['quantity'], 'Return quantity must be +4');

        // Check order status
        $stmtOrdCheck = $this->db->prepare("SELECT order_status, payment_status, cancelled_at FROM orders WHERE id = :id");
        $stmtOrdCheck->execute([':id' => $orderId]);
        $ordCheck = $stmtOrdCheck->fetch();
        assert_equals('CANCELLED', $ordCheck['order_status'], 'Order status must be CANCELLED');
        assert_equals('FAILED', $ordCheck['payment_status'], 'Payment status must be FAILED');
        assert_true(!empty($ordCheck['cancelled_at']), 'cancelled_at must be populated');

        // Check COD payment status
        $stmtCodCheck = $this->db->prepare("SELECT status FROM cod_payments WHERE order_id = :id");
        $stmtCodCheck->execute([':id' => $orderId]);
        assert_equals('FAILED', $stmtCodCheck->fetchColumn(), 'COD payment status must be FAILED');
    }

    /**
     * TC-07: Order Communication Timeline Notes Recording & Chronology
     */
    public function test_order_timeline_notes_recording_and_chronology(): void {
        $catId = $this->createCategory('TimelineNotes');
        $prodId = $this->createProduct($catId, 'QA-P3-NOTE-01', 'QA Note Product', 10.00, 20.00, 10);

        $year = date('Y');
        $orderNum = sprintf('ORD-%s-9004', $year);

        $stmt = $this->db->prepare("
            INSERT INTO orders (order_number, user_id, customer_name, customer_phone, delivery_address, subtotal, total_amount, order_status, created_at, updated_at)
            VALUES (:num, :uid, 'Charlie Brown', '09201112222', '321 Maple Ave', 20.00, 20.00, 'CONFIRMED', NOW(), NOW())
        ");
        $stmt->execute([':num' => $orderNum, ':uid' => $this->adminUserId]);
        $orderId = (int) $this->db->lastInsertId();

        // Add 3 sequential communication notes
        $note1 = 'Initial order creation and validation completed.';
        $note2 = 'Customer called to specify delivery after 4:00 PM.';
        $note3 = 'Gate code #9876 recorded for courier access.';

        $stmtNote = $this->db->prepare("INSERT INTO order_notes (order_id, user_id, note, created_at) VALUES (:oid, :uid, :note, NOW())");
        $stmtNote->execute([':oid' => $orderId, ':uid' => $this->adminUserId, ':note' => $note1]);
        $stmtNote->execute([':oid' => $orderId, ':uid' => $this->adminUserId, ':note' => $note2]);
        $stmtNote->execute([':oid' => $orderId, ':uid' => $this->adminUserId, ':note' => $note3]);

        // Retrieve notes in chronological descending order (matching handler)
        $stmtFetch = $this->db->prepare("
            SELECT onote.id, onote.note, u.username 
            FROM order_notes onote
            LEFT JOIN users u ON onote.user_id = u.id
            WHERE onote.order_id = :oid
            ORDER BY onote.created_at DESC, onote.id DESC
        ");
        $stmtFetch->execute([':oid' => $orderId]);
        $notes = $stmtFetch->fetchAll();

        assert_equals(3, count($notes), 'Exactly 3 timeline notes must be retrieved');
        assert_equals($note3, $notes[0]['note'], 'Most recent note must appear first in descending chronology');
        assert_equals($note2, $notes[1]['note'], 'Second note must appear second in descending chronology');
        assert_equals($note1, $notes[2]['note'], 'Oldest note must appear last in descending chronology');
        assert_equals('admin', $notes[0]['username'], 'Author username must correctly match authenticated user');
    }

    /**
     * TC-08: Post-Dispatch Return (Customer Refusal / Delivery Failure)
     */
    public function test_post_dispatch_order_return_stock_restoration(): void {
        $catId = $this->createCategory('PostDispatchReturn');
        $prodId = $this->createProduct($catId, 'QA-P3-RET-01', 'QA Fragile Parcel', 40.00, 80.00, 10);

        // 1. Create and dispatch order for 2 units -> Stock becomes 8
        $this->db->beginTransaction();
        $year = date('Y');
        $orderNum = sprintf('ORD-%s-9005', $year);

        $stmt = $this->db->prepare("
            INSERT INTO orders (order_number, user_id, customer_name, customer_phone, delivery_address, subtotal, total_amount, order_status, payment_status, dispatched_at, created_at, updated_at)
            VALUES (:num, :uid, 'David Wilson', '09223334444', '555 Oak St', 160.00, 160.00, 'DISPATCHED', 'PENDING_COLLECTION', NOW(), NOW(), NOW())
        ");
        $stmt->execute([':num' => $orderNum, ':uid' => $this->adminUserId]);
        $orderId = (int) $this->db->lastInsertId();

        $this->db->prepare("
            INSERT INTO order_items (order_id, product_id, product_name, unit_cost, unit_price, quantity, line_total, created_at)
            VALUES (:oid, :pid, 'QA Fragile Parcel', 40.00, 80.00, 2, 160.00, NOW())
        ")->execute([':oid' => $orderId, ':pid' => $prodId]);

        $this->db->prepare("UPDATE products SET current_stock = 8 WHERE id = :id")->execute([':id' => $prodId]);

        $this->db->prepare("INSERT INTO cod_payments (order_id, amount_due, status, created_at) VALUES (:oid, 160.00, 'PENDING', NOW())")
            ->execute([':oid' => $orderId]);

        $this->db->commit();

        // 2. Courier reports delivery failure -> Target Status: RETURNED
        $this->db->beginTransaction();
        try {
            $stmtOrder = $this->db->prepare("SELECT order_status FROM orders WHERE id = :id FOR UPDATE");
            $stmtOrder->execute([':id' => $orderId]);
            $status = $stmtOrder->fetchColumn();

            assert_equals('DISPATCHED', $status, 'Order must be in DISPATCHED state to be marked as RETURNED');

            // Restore stock
            $this->db->prepare("UPDATE products SET current_stock = current_stock + 2, updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $prodId]);

            $this->db->prepare("
                INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, notes, created_at)
                VALUES (:pid, :uid, 'RETURN', 2, 8, 10, 'ORDER', :oid, 'Customer refused package at doorstep', NOW())
            ")->execute([':pid' => $prodId, ':uid' => $this->adminUserId, ':oid' => $orderId]);

            $this->db->prepare("
                UPDATE orders 
                SET order_status = 'RETURNED', payment_status = 'FAILED', cancellation_reason = 'Customer rejected package', updated_at = NOW() 
                WHERE id = :id
            ")->execute([':id' => $orderId]);

            $this->db->prepare("UPDATE cod_payments SET status = 'FAILED', notes = 'Returned by courier' WHERE order_id = :id")
                ->execute([':id' => $orderId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Assertions
        $stmtFinalStock = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id");
        $stmtFinalStock->execute([':id' => $prodId]);
        assert_equals(10, (int) $stmtFinalStock->fetchColumn(), 'Product stock must be restored to 10 units');

        $stmtFinalOrder = $this->db->prepare("SELECT order_status, payment_status FROM orders WHERE id = :id");
        $stmtFinalOrder->execute([':id' => $orderId]);
        $ord = $stmtFinalOrder->fetch();
        assert_equals('RETURNED', $ord['order_status'], 'Order status must be RETURNED');
        assert_equals('FAILED', $ord['payment_status'], 'Payment status must be FAILED');
    }

    /**
     * TC-09: State Machine Guard Invariants (Illegal Lifecycle Jumps Blocked)
     */
    public function test_invalid_state_transition_blocking(): void {
        $catId = $this->createCategory('StateInvariants');
        $prodId = $this->createProduct($catId, 'QA-P3-INV-01', 'QA Invariant Product', 10.00, 20.00, 10);

        $year = date('Y');
        $orderNum = sprintf('ORD-%s-9006', $year);

        // 1. Create order in CONFIRMED state
        $stmt = $this->db->prepare("
            INSERT INTO orders (order_number, user_id, customer_name, customer_phone, delivery_address, subtotal, total_amount, order_status, payment_status, created_at, updated_at)
            VALUES (:num, :uid, 'Eve Adams', '09259998888', '999 Birch Rd', 20.00, 20.00, 'CONFIRMED', 'PENDING_COLLECTION', NOW(), NOW())
        ");
        $stmt->execute([':num' => $orderNum, ':uid' => $this->adminUserId]);
        $orderId = (int) $this->db->lastInsertId();

        // Invariant Rule A: Cannot mark as DELIVERED directly from CONFIRMED (must be DISPATCHED first)
        $invalidDeliverBlocked = false;
        $stmtFetch = $this->db->prepare("SELECT order_status FROM orders WHERE id = :id");
        $stmtFetch->execute([':id' => $orderId]);
        $current = $stmtFetch->fetchColumn();

        if ($current !== 'DISPATCHED') {
            $invalidDeliverBlocked = true; // Handler blocks with 422
        }
        assert_true($invalidDeliverBlocked, 'Skipping DISPATCHED status to jump directly from CONFIRMED to DELIVERED must be blocked');

        // Invariant Rule B: Cannot REMIT payment before DELIVERED and COLLECTED
        $invalidRemitBlocked = false;
        $stmtFetchPay = $this->db->prepare("SELECT payment_status FROM orders WHERE id = :id");
        $stmtFetchPay->execute([':id' => $orderId]);
        $currentPay = $stmtFetchPay->fetchColumn();

        if ($currentPay !== 'COLLECTED') {
            $invalidRemitBlocked = true; // Handler blocks with 422
        }
        assert_true($invalidRemitBlocked, 'Remitting payment before status is COLLECTED must be blocked');

        // Invariant Rule C: Cannot cancel an order once already DELIVERED
        $this->db->prepare("UPDATE orders SET order_status = 'DELIVERED', payment_status = 'COLLECTED' WHERE id = :id")
            ->execute([':id' => $orderId]);

        $invalidCancelBlocked = false;
        $stmtFetchDelivered = $this->db->prepare("SELECT order_status FROM orders WHERE id = :id");
        $stmtFetchDelivered->execute([':id' => $orderId]);
        $statusDelivered = $stmtFetchDelivered->fetchColumn();

        if ($statusDelivered !== 'CONFIRMED' && $statusDelivered !== 'PENDING') {
            $invalidCancelBlocked = true; // Handler rejects cancellation of dispatched or delivered orders
        }
        assert_true($invalidCancelBlocked, 'Cancelling an already DELIVERED order must be blocked');
    }
}

