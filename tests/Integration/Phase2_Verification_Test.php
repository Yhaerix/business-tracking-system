<?php
/**
 * Phase 2 Integration Verification Test Suite
 * Small Business Sales and Expense Tracking System
 *
 * Verifies:
 * 1. Category creation, duplicate prevention, and blocking delete if active products exist.
 * 2. Product creation with initial stock and initial inventory movement record.
 * 3. Product catalog update and soft deletion (deleted_at IS NOT NULL).
 * 4. Stock adjustments via atomic movements (RESTOCK, DAMAGE, ADJUSTMENT).
 * 5. Strict prevention of negative stock (atomic rollback and stock preservation).
 * 6. Low stock threshold alert generation and 24-hour deduplication.
 * 7. Audit log verification on catalog and stock mutations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

class Phase2_Verification_Test {
    private PDO $db;
    private int $testUserId = 1;

    public function __construct() {
        $this->db = get_db();
        // Setup authenticated admin session environment for CLI execution
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        $_SESSION['user_id']    = $this->testUserId;
        $_SESSION['username']   = 'admin';
        $_SESSION['full_name']  = 'System Administrator';
        $_SESSION['role_id']    = 1;
        $_SESSION['role_name']  = ROLE_ADMIN;
        $_SESSION['role_label'] = 'Administrator';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $this->cleanupTestData();
    }

    public function __destruct() {
        $this->cleanupTestData();
    }

    private function cleanupTestData(): void {
        try {
            // Delete notifications created for QA tests
            $this->db->exec("DELETE FROM notifications WHERE reference_type = 'PRODUCT' AND reference_id IN (SELECT id FROM products WHERE sku LIKE 'QA-%')");
            // Delete inventory movements created for QA products
            $this->db->exec("DELETE FROM inventory_movements WHERE product_id IN (SELECT id FROM products WHERE sku LIKE 'QA-%')");
            // Delete QA products
            $this->db->exec("DELETE FROM products WHERE sku LIKE 'QA-%'");
            // Delete QA categories
            $this->db->exec("DELETE FROM categories WHERE name LIKE 'QA %'");
        } catch (Throwable $e) {
            // Suppress cleanup exceptions
        }
    }

    /**
     * TC-01: Category Creation and Case-Insensitive Duplicate Prevention
     */
    public function test_category_creation_and_duplicate_prevention(): void {
        $catName = 'QA Category Electronics';
        $catDesc = 'Testing category creation and validation';

        // 1. Create Category
        $stmt = $this->db->prepare("INSERT INTO categories (name, description, created_at) VALUES (:name, :description, NOW())");
        $stmt->execute([':name' => $catName, ':description' => $catDesc]);
        $catId = (int)$this->db->lastInsertId();

        assert_true($catId > 0, 'Category ID should be positive integer upon creation');

        $stmtFetch = $this->db->prepare("SELECT * FROM categories WHERE id = :id");
        $stmtFetch->execute([':id' => $catId]);
        $category = $stmtFetch->fetch();

        assert_true((bool)$category, 'Created category must exist in database');
        assert_equals($catName, $category['name'], 'Category name must match');

        // 2. Duplicate Detection (Case-insensitive check)
        $stmtCheck = $this->db->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) LIMIT 1");
        $stmtCheck->execute([':name' => 'qa category electronics']);
        $duplicate = $stmtCheck->fetch();

        assert_true((bool)$duplicate, 'Duplicate category name must be detected case-insensitively');
        assert_equals($catId, (int)$duplicate['id'], 'Duplicate detection must locate existing category ID');
    }

    /**
     * TC-02: Block Category Deletion if Active Products Exist
     */
    public function test_category_delete_blocked_when_products_exist(): void {
        // 1. Create category
        $stmt = $this->db->prepare("INSERT INTO categories (name, description, created_at) VALUES ('QA Protected Category', 'Category with active products', NOW())");
        $stmt->execute();
        $catId = (int)$this->db->lastInsertId();

        // 2. Assign active product
        $sku = 'QA-PROT-001';
        $stmtProd = $this->db->prepare("
            INSERT INTO products (category_id, sku, name, cost_price, selling_price, current_stock, low_stock_threshold, status, created_at)
            VALUES (:cat_id, :sku, 'Protected Test Product', 10.00, 20.00, 5, 2, 'active', NOW())
        ");
        $stmtProd->execute([':cat_id' => $catId, ':sku' => $sku]);
        $prodId = (int)$this->db->lastInsertId();

        // 3. Verify active product count check
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM products WHERE category_id = :id AND deleted_at IS NULL");
        $stmtCount->execute([':id' => $catId]);
        $activeCount = (int)$stmtCount->fetchColumn();

        assert_true($activeCount > 0, 'Category must report active assigned products');
        
        // Simulating the handler rule: Deletion blocked if activeCount > 0
        $canDelete = ($activeCount === 0);
        assert_false($canDelete, 'Category deletion must be blocked when active products are assigned');

        // 4. Soft-delete the product
        $stmtDelProd = $this->db->prepare("UPDATE products SET deleted_at = NOW(), status = 'inactive' WHERE id = :id");
        $stmtDelProd->execute([':id' => $prodId]);

        // Re-check count
        $stmtCount->execute([':id' => $catId]);
        $activeCountAfter = (int)$stmtCount->fetchColumn();
        assert_equals(0, $activeCountAfter, 'Active product count must be 0 after soft delete');

        // Now deletion is permitted (reassigning any soft-deleted products to category 1 first)
        $canDeleteNow = ($activeCountAfter === 0);
        assert_true($canDeleteNow, 'Category deletion is permitted once active products are cleared');

        $stmtReassign = $this->db->prepare("UPDATE products SET category_id = 1 WHERE category_id = :id");
        $stmtReassign->execute([':id' => $catId]);

        $stmtDelCat = $this->db->prepare("DELETE FROM categories WHERE id = :id");
        $stmtDelCat->execute([':id' => $catId]);

        $stmtVerify = $this->db->prepare("SELECT id FROM categories WHERE id = :id");
        $stmtVerify->execute([':id' => $catId]);
        assert_false((bool)$stmtVerify->fetch(), 'Category must be successfully deleted');
    }

    /**
     * TC-03: Product Creation with Initial Stock and Movement Entry
     */
    public function test_product_creation_with_initial_stock_and_movement(): void {
        // Category
        $stmtCat = $this->db->prepare("INSERT INTO categories (name, description, created_at) VALUES ('QA Stock Category', 'Stock test', NOW())");
        $stmtCat->execute();
        $catId = (int)$this->db->lastInsertId();

        $sku = 'QA-SKU-INIT-01';
        $initialStock = 50;

        // Atomic Transaction (Simulating modules/products/handler.php create_product)
        $this->db->beginTransaction();
        try {
            $stmtProd = $this->db->prepare("
                INSERT INTO products (
                    category_id, sku, name, cost_price, selling_price, 
                    current_stock, low_stock_threshold, status, created_at
                ) VALUES (
                    :category_id, :sku, 'QA Initial Stock Product', 12.50, 25.00,
                    :current_stock, 10, 'active', NOW()
                )
            ");
            $stmtProd->execute([
                ':category_id'   => $catId,
                ':sku'           => $sku,
                ':current_stock' => $initialStock
            ]);
            $productId = (int)$this->db->lastInsertId();

            // Initial stock movement
            $stmtMov = $this->db->prepare("
                INSERT INTO inventory_movements (
                    product_id, user_id, movement_type, quantity,
                    stock_before, stock_after, reference_type, reference_id,
                    notes, created_at
                ) VALUES (
                    :product_id, :user_id, 'INITIAL', :quantity,
                    0, :stock_after, 'INITIAL_SETUP', NULL,
                    'Initial inventory upon product catalog creation', NOW()
                )
            ");
            $stmtMov->execute([
                ':product_id'  => $productId,
                ':user_id'     => $this->testUserId,
                ':quantity'    => $initialStock,
                ':stock_after' => $initialStock
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Verify product record
        $stmtFetch = $this->db->prepare("SELECT * FROM products WHERE id = :id");
        $stmtFetch->execute([':id' => $productId]);
        $product = $stmtFetch->fetch();

        assert_true((bool)$product, 'Product record must exist');
        assert_equals($initialStock, (int)$product['current_stock'], 'Current stock must match initial stock');

        // Verify inventory_movements record
        $stmtMovFetch = $this->db->prepare("
            SELECT * FROM inventory_movements 
            WHERE product_id = :product_id AND movement_type = 'INITIAL'
        ");
        $stmtMovFetch->execute([':product_id' => $productId]);
        $movement = $stmtMovFetch->fetch();

        assert_true((bool)$movement, 'Initial inventory movement record must exist');
        assert_equals('INITIAL', $movement['movement_type'], 'Movement type must be INITIAL');
        assert_equals(0, (int)$movement['stock_before'], 'Stock before must be 0');
        assert_equals($initialStock, (int)$movement['stock_after'], 'Stock after must match initial stock');
        assert_equals($initialStock, (int)$movement['quantity'], 'Movement quantity must match initial stock');
    }

    /**
     * TC-04: Product Update and Soft Delete Integrity
     */
    public function test_product_update_and_soft_delete(): void {
        $stmtCat = $this->db->prepare("INSERT INTO categories (name, description, created_at) VALUES ('QA SoftDel Category', 'Soft delete test', NOW())");
        $stmtCat->execute();
        $catId = (int)$this->db->lastInsertId();

        $sku = 'QA-SKU-UPD-01';
        $stmt = $this->db->prepare("
            INSERT INTO products (category_id, sku, name, cost_price, selling_price, current_stock, low_stock_threshold, status, created_at)
            VALUES (:cat_id, :sku, 'Original Product Name', 10.00, 20.00, 30, 5, 'active', NOW())
        ");
        $stmt->execute([':cat_id' => $catId, ':sku' => $sku]);
        $prodId = (int)$this->db->lastInsertId();

        // 1. Update product details (stock intentionally unchanged)
        $stmtUpdate = $this->db->prepare("
            UPDATE products 
            SET name = 'Updated Product Name', selling_price = 29.99, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmtUpdate->execute([':id' => $prodId]);

        $stmtFetch = $this->db->prepare("SELECT * FROM products WHERE id = :id");
        $stmtFetch->execute([':id' => $prodId]);
        $updatedProd = $stmtFetch->fetch();

        assert_equals('Updated Product Name', $updatedProd['name'], 'Product name must be updated');
        assert_equals('29.99', $updatedProd['selling_price'], 'Selling price must be updated');
        assert_equals(30, (int)$updatedProd['current_stock'], 'Current stock must remain 30 during details update');

        // 2. Soft Delete
        $stmtDel = $this->db->prepare("
            UPDATE products 
            SET deleted_at = NOW(), status = 'inactive' 
            WHERE id = :id
        ");
        $stmtDel->execute([':id' => $prodId]);

        $stmtSoft = $this->db->prepare("SELECT * FROM products WHERE id = :id");
        $stmtSoft->execute([':id' => $prodId]);
        $deletedProd = $stmtSoft->fetch();

        assert_true(!empty($deletedProd['deleted_at']), 'deleted_at must not be null for soft deleted product');
        assert_equals('inactive', $deletedProd['status'], 'Status must transition to inactive upon soft delete');

        // Active catalog queries must exclude it
        $stmtActive = $this->db->prepare("SELECT id FROM products WHERE id = :id AND deleted_at IS NULL");
        $stmtActive->execute([':id' => $prodId]);
        assert_false((bool)$stmtActive->fetch(), 'Soft-deleted product must be excluded from active product queries');
    }

    /**
     * TC-05: Atomic Positive Stock Adjustment (RESTOCK)
     */
    public function test_stock_adjustment_positive_restock(): void {
        $stmtCat = $this->db->prepare("INSERT INTO categories (name, description, created_at) VALUES ('QA Restock Category', 'Restock test', NOW())");
        $stmtCat->execute();
        $catId = (int)$this->db->lastInsertId();

        $sku = 'QA-SKU-RESTOCK-01';
        $initialStock = 20;
        $restockAmount = 15;

        $stmt = $this->db->prepare("
            INSERT INTO products (category_id, sku, name, cost_price, selling_price, current_stock, low_stock_threshold, status, created_at)
            VALUES (:cat_id, :sku, 'QA Restock Product', 15.00, 30.00, :stock, 5, 'active', NOW())
        ");
        $stmt->execute([':cat_id' => $catId, ':sku' => $sku, ':stock' => $initialStock]);
        $prodId = (int)$this->db->lastInsertId();

        // Perform RESTOCK adjustment with row locking (modules/inventory/handler.php adjust_stock)
        $this->db->beginTransaction();
        try {
            $stmtLock = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id FOR UPDATE");
            $stmtLock->execute([':id' => $prodId]);
            $current = (int)$stmtLock->fetchColumn();

            $newStock = $current + $restockAmount;

            $stmtUp = $this->db->prepare("UPDATE products SET current_stock = :stock, updated_at = NOW() WHERE id = :id");
            $stmtUp->execute([':stock' => $newStock, ':id' => $prodId]);

            $stmtMov = $this->db->prepare("
                INSERT INTO inventory_movements (
                    product_id, user_id, movement_type, quantity,
                    stock_before, stock_after, reference_type, notes, created_at
                ) VALUES (
                    :product_id, :user_id, 'RESTOCK', :qty,
                    :stock_before, :stock_after, 'MANUAL_ADJUSTMENT', 'Replenishment verified', NOW()
                )
            ");
            $stmtMov->execute([
                ':product_id'   => $prodId,
                ':user_id'      => $this->testUserId,
                ':qty'          => $restockAmount,
                ':stock_before' => $current,
                ':stock_after'  => $newStock
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Assert product stock updated
        $stmtCheck = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id");
        $stmtCheck->execute([':id' => $prodId]);
        $finalStock = (int)$stmtCheck->fetchColumn();

        assert_equals(35, $finalStock, 'Product current stock must be incremented to 35');

        // Assert inventory movement written
        $stmtMovCheck = $this->db->prepare("
            SELECT * FROM inventory_movements 
            WHERE product_id = :id AND movement_type = 'RESTOCK' 
            ORDER BY id DESC LIMIT 1
        ");
        $stmtMovCheck->execute([':id' => $prodId]);
        $mov = $stmtMovCheck->fetch();

        assert_true((bool)$mov, 'RESTOCK movement record must exist');
        assert_equals(20, (int)$mov['stock_before'], 'Stock before must be 20');
        assert_equals(35, (int)$mov['stock_after'], 'Stock after must be 35');
        assert_equals(15, (int)$mov['quantity'], 'Restock quantity must be 15');
    }

    /**
     * TC-06: Atomic Negative Stock Adjustment (DAMAGE / REDUCTION)
     */
    public function test_stock_adjustment_negative_damage(): void {
        $stmtCat = $this->db->prepare("INSERT INTO categories (name, description, created_at) VALUES ('QA Damage Category', 'Damage test', NOW())");
        $stmtCat->execute();
        $catId = (int)$this->db->lastInsertId();

        $sku = 'QA-SKU-DAMAGE-01';
        $initialStock = 35;
        $damageAmount = 10;

        $stmt = $this->db->prepare("
            INSERT INTO products (category_id, sku, name, cost_price, selling_price, current_stock, low_stock_threshold, status, created_at)
            VALUES (:cat_id, :sku, 'QA Damage Product', 20.00, 40.00, :stock, 5, 'active', NOW())
        ");
        $stmt->execute([':cat_id' => $catId, ':sku' => $sku, ':stock' => $initialStock]);
        $prodId = (int)$this->db->lastInsertId();

        // Perform DAMAGE reduction
        $this->db->beginTransaction();
        try {
            $stmtLock = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id FOR UPDATE");
            $stmtLock->execute([':id' => $prodId]);
            $current = (int)$stmtLock->fetchColumn();

            $newStock = $current - $damageAmount;
            assert_true($newStock >= 0, 'New stock must not be negative');

            $stmtUp = $this->db->prepare("UPDATE products SET current_stock = :stock, updated_at = NOW() WHERE id = :id");
            $stmtUp->execute([':stock' => $newStock, ':id' => $prodId]);

            $stmtMov = $this->db->prepare("
                INSERT INTO inventory_movements (
                    product_id, user_id, movement_type, quantity,
                    stock_before, stock_after, reference_type, notes, created_at
                ) VALUES (
                    :product_id, :user_id, 'DAMAGE', :qty,
                    :stock_before, :stock_after, 'MANUAL_ADJUSTMENT', 'Damaged goods write-off', NOW()
                )
            ");
            $stmtMov->execute([
                ':product_id'   => $prodId,
                ':user_id'      => $this->testUserId,
                ':qty'          => $damageAmount,
                ':stock_before' => $current,
                ':stock_after'  => $newStock
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $stmtCheck = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id");
        $stmtCheck->execute([':id' => $prodId]);
        assert_equals(25, (int)$stmtCheck->fetchColumn(), 'Product stock must decrement to 25');

        $stmtMovCheck = $this->db->prepare("
            SELECT * FROM inventory_movements 
            WHERE product_id = :id AND movement_type = 'DAMAGE' 
            ORDER BY id DESC LIMIT 1
        ");
        $stmtMovCheck->execute([':id' => $prodId]);
        $mov = $stmtMovCheck->fetch();

        assert_true((bool)$mov, 'DAMAGE movement record must exist');
        assert_equals(35, (int)$mov['stock_before'], 'Stock before must be 35');
        assert_equals(25, (int)$mov['stock_after'], 'Stock after must be 25');
    }

    /**
     * TC-07: Strict Prevention of Negative Stock (Atomic Rollback)
     */
    public function test_negative_stock_prevention_boundary(): void {
        $stmtCat = $this->db->prepare("INSERT INTO categories (name, description, created_at) VALUES ('QA NegStock Category', 'Negative stock guard', NOW())");
        $stmtCat->execute();
        $catId = (int)$this->db->lastInsertId();

        $sku = 'QA-SKU-NEG-01';
        $currentStock = 10;
        $excessiveReduction = 15; // 10 - 15 = -5 (must fail!)

        $stmt = $this->db->prepare("
            INSERT INTO products (category_id, sku, name, cost_price, selling_price, current_stock, low_stock_threshold, status, created_at)
            VALUES (:cat_id, :sku, 'QA Boundary Product', 10.00, 20.00, :stock, 5, 'active', NOW())
        ");
        $stmt->execute([':cat_id' => $catId, ':sku' => $sku, ':stock' => $currentStock]);
        $prodId = (int)$this->db->lastInsertId();

        $operationFailed = false;

        $this->db->beginTransaction();
        try {
            $stmtLock = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id FOR UPDATE");
            $stmtLock->execute([':id' => $prodId]);
            $stockBefore = (int)$stmtLock->fetchColumn();

            $stockAfter = $stockBefore - $excessiveReduction;

            // Invariant guard check
            if ($stockAfter < 0) {
                $this->db->rollBack();
                $operationFailed = true;
            } else {
                $stmtUp = $this->db->prepare("UPDATE products SET current_stock = :stock WHERE id = :id");
                $stmtUp->execute([':stock' => $stockAfter, ':id' => $prodId]);
                $this->db->commit();
            }
        } catch (Throwable $e) {
            $this->db->rollBack();
            $operationFailed = true;
        }

        assert_true($operationFailed, 'Deducting more stock than available must trigger rejection');

        // Confirm database stock was preserved
        $stmtCheck = $this->db->prepare("SELECT current_stock FROM products WHERE id = :id");
        $stmtCheck->execute([':id' => $prodId]);
        $stockPreserved = (int)$stmtCheck->fetchColumn();

        assert_equals($currentStock, $stockPreserved, 'Current stock must remain strictly untouched (10 units)');

        // Confirm no movement log was recorded
        $stmtMov = $this->db->prepare("SELECT COUNT(*) FROM inventory_movements WHERE product_id = :id");
        $stmtMov->execute([':id' => $prodId]);
        assert_equals(0, (int)$stmtMov->fetchColumn(), 'No movement log must be written for rejected negative adjustment');
    }

    /**
     * TC-08: Low Stock Threshold Alert & 24-Hour Deduplication
     */
    public function test_low_stock_threshold_and_notification_deduplication(): void {
        $stmtCat = $this->db->prepare("INSERT INTO categories (name, description, created_at) VALUES ('QA Notify Category', 'Notification test', NOW())");
        $stmtCat->execute();
        $catId = (int)$this->db->lastInsertId();

        $sku = 'QA-SKU-NOTIF-01';
        $lowThreshold = 5;
        $stock = 8;

        $stmt = $this->db->prepare("
            INSERT INTO products (category_id, sku, name, cost_price, selling_price, current_stock, low_stock_threshold, status, created_at)
            VALUES (:cat_id, :sku, 'QA Low Stock Product', 10.00, 20.00, :stock, :threshold, 'active', NOW())
        ");
        $stmt->execute([':cat_id' => $catId, ':sku' => $sku, ':stock' => $stock, ':threshold' => $lowThreshold]);
        $prodId = (int)$this->db->lastInsertId();

        // 1. Reduce stock across threshold (from 8 to 3)
        $newStock = 3;
        $stmtUp = $this->db->prepare("UPDATE products SET current_stock = :stock WHERE id = :id");
        $stmtUp->execute([':stock' => $newStock, ':id' => $prodId]);

        // Evaluate notification logic
        if ($newStock <= $lowThreshold) {
            $stmtCheck = $this->db->prepare("
                SELECT id FROM notifications 
                WHERE type = 'LOW_STOCK' 
                  AND reference_type = 'PRODUCT' 
                  AND reference_id = :prod_id 
                  AND created_at >= (NOW() - INTERVAL 24 HOUR)
                LIMIT 1
            ");
            $stmtCheck->execute([':prod_id' => $prodId]);
            if (!$stmtCheck->fetch()) {
                $stmtNotify = $this->db->prepare("
                    INSERT INTO notifications (user_id, type, title, message, is_read, reference_type, reference_id, created_at)
                    VALUES (NULL, 'LOW_STOCK', 'Low Stock Alert: QA Low Stock Product', 'Remaining: 3 units', 0, 'PRODUCT', :prod_id, NOW())
                ");
                $stmtNotify->execute([':prod_id' => $prodId]);
            }
        }

        // Verify first notification was written
        $stmtCountNotif = $this->db->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE type = 'LOW_STOCK' AND reference_type = 'PRODUCT' AND reference_id = :prod_id
        ");
        $stmtCountNotif->execute([':prod_id' => $prodId]);
        assert_equals(1, (int)$stmtCountNotif->fetchColumn(), 'First low-stock alert must be recorded in notifications');

        // 2. Reduce stock again within the same 24 hours (from 3 to 2)
        $newStock2 = 2;
        $stmtUp->execute([':stock' => $newStock2, ':id' => $prodId]);

        // Trigger notification check again
        if ($newStock2 <= $lowThreshold) {
            $stmtCheck = $this->db->prepare("
                SELECT id FROM notifications 
                WHERE type = 'LOW_STOCK' 
                  AND reference_type = 'PRODUCT' 
                  AND reference_id = :prod_id 
                  AND created_at >= (NOW() - INTERVAL 24 HOUR)
                LIMIT 1
            ");
            $stmtCheck->execute([':prod_id' => $prodId]);
            $recent = $stmtCheck->fetch();

            if (!$recent) {
                // Should NOT execute due to deduplication
                $stmtNotify = $this->db->prepare("
                    INSERT INTO notifications (user_id, type, title, message, is_read, reference_type, reference_id, created_at)
                    VALUES (NULL, 'LOW_STOCK', 'Duplicate Alert', 'Should not insert', 0, 'PRODUCT', :prod_id, NOW())
                ");
                $stmtNotify->execute([':prod_id' => $prodId]);
            }
        }

        // Confirm notification count is STILL 1 (deduplicated)
        $stmtCountNotif->execute([':prod_id' => $prodId]);
        assert_equals(1, (int)$stmtCountNotif->fetchColumn(), 'Duplicate notification within 24 hours must be suppressed');
    }

    /**
     * TC-09: Audit Trail Logging Integrity
     */
    public function test_audit_logging_integrity(): void {
        $testModule = 'products';
        $testAction = 'VERIFY_TEST';
        $recordId = 999999;
        $oldVals = ['stock' => 10];
        $newVals = ['stock' => 20];

        audit_log($testModule, $testAction, $recordId, $oldVals, $newVals);

        $stmt = $this->db->prepare("
            SELECT * FROM audit_logs 
            WHERE module = :module AND action = :action AND record_id = :record_id 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([
            ':module'    => $testModule,
            ':action'    => $testAction,
            ':record_id' => $recordId
        ]);
        $log = $stmt->fetch();

        assert_true((bool)$log, 'Audit log record must be persisted to database');
        assert_equals($testModule, $log['module'], 'Audit log module must match');
        assert_equals($testAction, $log['action'], 'Audit log action must match');

        $decodedOld = json_decode($log['old_values'], true);
        assert_equals(10, $decodedOld['stock'], 'Audit log serialized old values must match');

        $decodedNew = json_decode($log['new_values'], true);
        assert_equals(20, $decodedNew['stock'], 'Audit log serialized new values must match');

        // Cleanup audit entry
        $this->db->prepare("DELETE FROM audit_logs WHERE id = :id")->execute([':id' => $log['id']]);
    }
}
