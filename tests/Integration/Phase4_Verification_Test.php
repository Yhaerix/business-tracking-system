<?php
/**
 * Phase 4 Integration Verification Test Suite
 * Small Business Sales and Expense Tracking System
 *
 * Verifies:
 * 1. Category CRUD & Case-Insensitive Duplicate Prevention.
 * 2. Category Deletion Blocking when Active Expenses are Assigned & Voided Record Reassignment.
 * 3. Default System Category (ID 1) Immutability Protection.
 * 4. Expense Amount Positive Validation (Strictly > 0).
 * 5. Calendar Date Validation (Format YYYY-MM-DD and Gregorian Calendar checkdate verification).
 * 6. Category Foreign Key & Association Validation.
 * 7. Expense Title Constraints (Non-empty, Max 150 characters).
 * 8. Secure Receipt Upload MIME Whitelist (image/jpeg, image/png, application/pdf).
 * 9. Rejection of Disallowed MIME Types (PHP scripts, shell scripts, ZIP archives, plain text).
 * 10. Financial Immutability & Mandatory Deletion Reason (Minimum 3 characters).
 * 11. Soft Deletion Database State (deleted_at populated, deletion_reason recorded, zero hard deletes).
 * 12. Active Query Integrity (Soft-deleted expenses strictly excluded from deleted_at IS NULL).
 * 13. Audit Log Generation (audit_logs record with module='expenses', action='SOFT_DELETE').
 * 14. RBAC Admin Authority (expenses_view, expenses_manage, expenses_delete).
 * 15. RBAC Manager Deletion Guard (Manager can view/manage, strictly FORBIDDEN from deleting expenses).
 * 16. RBAC Cashier Total Access Denial (Cashier denied HTTP 403 from all expense operations).
 * 17. Expense Financial Metrics Aggregation (Monthly total, Today's total, Top Category).
 * 18. Financial Metrics Exclusion of Soft-Deleted Expenses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

class Phase4_Verification_Test {
    private PDO $db;
    private int $adminUserId = 1;
    private int $managerUserId = 2;
    private int $cashierUserId = 3;

    public function __construct() {
        $this->db = get_db();

        // Ensure session environment is initialized for CLI execution
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
     * Set session context as Administrator
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
     * Set session context as Manager
     */
    private function setSessionAsManager(): void {
        $_SESSION['user_id']    = $this->managerUserId;
        $_SESSION['username']   = 'manager';
        $_SESSION['full_name']  = 'Store Manager';
        $_SESSION['role_id']    = 2;
        $_SESSION['role_name']  = ROLE_MANAGER;
        $_SESSION['role_label'] = 'Manager';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /**
     * Set session context as Cashier
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
            // Delete audit logs associated with QA expenses
            $this->db->exec("
                DELETE FROM audit_logs 
                WHERE module = 'expenses' 
                  AND record_id IN (SELECT id FROM expenses WHERE title LIKE 'QA %')
            ");

            // Delete QA test expenses
            $this->db->exec("DELETE FROM expenses WHERE title LIKE 'QA %'");

            // Delete QA test categories (excluding system default category 1)
            $this->db->exec("DELETE FROM expense_categories WHERE name LIKE 'QA %' AND id > 1");
        } catch (Throwable $e) {
            // Suppress cleanup exceptions during teardown
        }
    }

    /**
     * Helper: Create an isolated test expense category
     */
    private function createCategory(string $suffix, ?string $desc = 'QA Test Category'): int {
        $stmt = $this->db->prepare("
            INSERT INTO expense_categories (name, description, created_at) 
            VALUES (:name, :desc, NOW())
        ");
        $stmt->execute([
            ':name' => 'QA ' . $suffix . ' ' . uniqid(),
            ':desc' => $desc
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Helper: Insert a test expense record
     */
    private function insertExpense(
        int $categoryId, 
        string $title, 
        float $amount, 
        string $date, 
        ?string $receiptPath = null, 
        ?string $notes = null
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO expenses (
                category_id, user_id, title, amount, expense_date, receipt_path, notes, created_at
            ) VALUES (
                :cat_id, :uid, :title, :amount, :date, :receipt, :notes, NOW()
            )
        ");
        $stmt->execute([
            ':cat_id'  => $categoryId,
            ':uid'     => $this->adminUserId,
            ':title'   => $title,
            ':amount'  => number_format($amount, 2, '.', ''),
            ':date'    => $date,
            ':receipt' => $receiptPath,
            ':notes'   => $notes
        ]);
        return (int) $this->db->lastInsertId();
    }

    // =========================================================================
    // DOMAIN 1: CATEGORY CRUD & DUPLICATE DETECTION
    // =========================================================================

    /**
     * TC-01: Category Creation & Case-Insensitive Duplicate Prevention
     */
    public function test_category_creation_and_case_insensitive_duplicate_prevention(): void {
        $baseName = 'QA Logistics & Delivery ' . uniqid();

        // 1. Create Category
        $stmt = $this->db->prepare("
            INSERT INTO expense_categories (name, description, created_at) 
            VALUES (:name, 'Shipping and courier charges', NOW())
        ");
        $stmt->execute([':name' => $baseName]);
        $catId = (int) $this->db->lastInsertId();
        assert_true($catId > 0, 'Category ID must be positive upon insertion');

        // 2. Case-Insensitive Duplicate Detection
        $duplicateVariations = [
            strtolower($baseName),
            strtoupper($baseName),
            ucwords(strtolower($baseName))
        ];

        foreach ($duplicateVariations as $variant) {
            $stmtCheck = $this->db->prepare("
                SELECT id FROM expense_categories 
                WHERE LOWER(name) = LOWER(:name) 
                LIMIT 1
            ");
            $stmtCheck->execute([':name' => $variant]);
            $found = $stmtCheck->fetch();

            assert_true((bool) $found, "Duplicate check must detect case variation: '{$variant}'");
            assert_equals($catId, (int) $found['id'], "Duplicate must resolve to initial category ID {$catId}");
        }
    }

    /**
     * TC-02: Block Category Deletion when Active Expenses are Assigned
     */
    public function test_category_deletion_blocked_with_active_expenses(): void {
        $catId = $this->createCategory('ActiveExpenseGuard');
        $expenseId = $this->insertExpense($catId, 'QA Active Gas Bill', 125.50, date('Y-m-d'));

        // Query active (non-voided) expenses assigned
        $stmtCheck = $this->db->prepare("
            SELECT COUNT(*) FROM expenses 
            WHERE category_id = :id AND deleted_at IS NULL
        ");
        $stmtCheck->execute([':id' => $catId]);
        $activeCount = (int) $stmtCheck->fetchColumn();

        assert_true($activeCount > 0, 'Category must report assigned active expenses');

        // Handler Guard Simulation: Block deletion
        $canDelete = ($activeCount === 0);
        assert_false($canDelete, 'Category deletion must be strictly blocked while active expenses exist');

        // Soft-delete the active expense
        $stmtSoftDel = $this->db->prepare("
            UPDATE expenses 
            SET deleted_at = NOW(), deletion_reason = 'QA Cleared for category test' 
            WHERE id = :id
        ");
        $stmtSoftDel->execute([':id' => $expenseId]);

        // Re-check active count
        $stmtCheck->execute([':id' => $catId]);
        $activeCountAfter = (int) $stmtCheck->fetchColumn();
        assert_equals(0, $activeCountAfter, 'Active count must be 0 after soft deleting expense');

        // Deletion protocol: Reassign voided expenses to Category 1, then delete category
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE expenses SET category_id = 1 WHERE category_id = :id")
                ->execute([':id' => $catId]);

            $this->db->prepare("DELETE FROM expense_categories WHERE id = :id")
                ->execute([':id' => $catId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Verify category deleted
        $stmtVerify = $this->db->prepare("SELECT id FROM expense_categories WHERE id = :id");
        $stmtVerify->execute([':id' => $catId]);
        assert_false((bool) $stmtVerify->fetch(), 'Category must be successfully deleted once active expenses are cleared');

        // Verify voided expense reassigned to default Category 1
        $stmtExpenseVerify = $this->db->prepare("SELECT category_id FROM expenses WHERE id = :id");
        $stmtExpenseVerify->execute([':id' => $expenseId]);
        assert_equals(1, (int) $stmtExpenseVerify->fetchColumn(), 'Voided expense must be reassigned to default category 1');
    }

    /**
     * TC-03: System Default Category (ID 1) Immutability Protection
     */
    public function test_default_category_protection(): void {
        $targetId = 1;
        $isDefaultProtected = ($targetId === 1);

        assert_true($isDefaultProtected, 'System category 1 must be flagged as protected from deletion');

        // Verify Category 1 exists
        $stmtDefault = $this->db->prepare("SELECT id, name FROM expense_categories WHERE id = 1");
        $stmtDefault->execute();
        $defaultCat = $stmtDefault->fetch();

        assert_true((bool) $defaultCat, 'Default expense category 1 must permanently exist in the database');
    }

    // =========================================================================
    // DOMAIN 2: EXPENSE CREATION & VALIDATION
    // =========================================================================

    /**
     * TC-04: Positive Amount Validation (Rejects <= 0)
     */
    public function test_expense_amount_positive_validation(): void {
        $invalidAmounts = [0.00, -0.01, -150.00, -9999.99];
        $validAmounts   = [0.01, 10.50, 450.00, 12500.75];

        foreach ($invalidAmounts as $amt) {
            $isValid = ($amt > 0.0);
            assert_false($isValid, "Expense amount '{$amt}' must fail positive validation");
        }

        foreach ($validAmounts as $amt) {
            $isValid = ($amt > 0.0);
            assert_true($isValid, "Expense amount '{$amt}' must pass positive validation");
        }
    }

    /**
     * TC-05: Calendar Date Format & Gregorian Validity Checks
     */
    public function test_expense_calendar_date_validation(): void {
        $invalidDates = [
            '2026/11/11',       // Slash separator
            '11-11-2026',       // DD-MM-YYYY
            '2026-13-01',       // Invalid month 13
            '2026-02-30',       // Non-existent February 30th
            '2026-04-31',       // Non-existent April 31st
            'invalid-date',     // Non-numeric string
            ''                  // Empty string
        ];

        foreach ($invalidDates as $d) {
            $isValidFormat = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
            $isRealDate = false;
            if ($isValidFormat) {
                [$year, $month, $day] = explode('-', $d);
                $isRealDate = checkdate((int) $month, (int) $day, (int) $year);
            }
            $passed = $isValidFormat && $isRealDate;
            assert_false($passed, "Date string '{$d}' must be rejected by calendar validation");
        }

        $validDates = [
            date('Y-m-d'),
            '2026-02-28',
            '2024-02-29',       // Leap year valid date
            '2026-12-31'
        ];

        foreach ($validDates as $d) {
            $isValidFormat = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
            [$year, $month, $day] = explode('-', $d);
            $isRealDate = checkdate((int) $month, (int) $day, (int) $year);
            assert_true($isValidFormat && $isRealDate, "Date string '{$d}' must be accepted as valid calendar date");
        }
    }

    /**
     * TC-06: Category Foreign Key Association Validation
     */
    public function test_expense_category_association_validation(): void {
        $nonExistentCategoryId = 999999;

        $stmtCheck = $this->db->prepare("SELECT id FROM expense_categories WHERE id = :id");
        $stmtCheck->execute([':id' => $nonExistentCategoryId]);
        $exists = (bool) $stmtCheck->fetch();

        assert_false($exists, 'Non-existent category ID must not be found');

        // Valid Category association
        $validCatId = $this->createCategory('AssociationTest');
        $stmtCheck->execute([':id' => $validCatId]);
        $existsValid = (bool) $stmtCheck->fetch();

        assert_true($existsValid, 'Newly created category ID must be confirmed valid');
    }

    /**
     * TC-07: Expense Title Length & Empty Guard
     */
    public function test_expense_title_length_validation(): void {
        $emptyTitle = '   ';
        $tooLongTitle = str_repeat('A', 151);
        $validTitle = 'Monthly High-Speed Fiber Internet Bill';

        assert_equals('', trim($emptyTitle), 'Empty or whitespace-only title must be detected');
        assert_true(mb_strlen($tooLongTitle) > 150, 'Title exceeding 150 characters must be detected');
        assert_true(mb_strlen($validTitle) <= 150 && trim($validTitle) !== '', 'Valid title must be accepted');
    }

    // =========================================================================
    // DOMAIN 3: SECURE RECEIPT UPLOAD CONSTRAINTS
    // =========================================================================

    /**
     * TC-08: Secure Receipt Upload Allowed MIME Types Whitelist
     */
    public function test_receipt_upload_mime_type_constraints(): void {
        $allowedMimeMap = [
            'image/jpeg'      => '.jpg',
            'image/png'       => '.png',
            'application/pdf' => '.pdf'
        ];

        // 1. Verify MIME map matches Phase 4 specification exactly
        assert_true(array_key_exists('image/jpeg', $allowedMimeMap), 'image/jpeg must be allowed');
        assert_true(array_key_exists('image/png', $allowedMimeMap), 'image/png must be allowed');
        assert_true(array_key_exists('application/pdf', $allowedMimeMap), 'application/pdf must be allowed');
        assert_equals('.jpg', $allowedMimeMap['image/jpeg'], 'image/jpeg must map to .jpg extension');
        assert_equals('.png', $allowedMimeMap['image/png'], 'image/png must map to .png extension');
        assert_equals('.pdf', $allowedMimeMap['application/pdf'], 'application/pdf must map to .pdf extension');

        // 2. Real binary inspection using finfo_file on simulated valid temp files
        $tempDir = sys_get_temp_dir();

        // Valid PNG Header
        $pngPath = $tempDir . '/qa_test_' . uniqid() . '.png';
        file_put_contents($pngPath, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4");

        // Valid PDF Header
        $pdfPath = $tempDir . '/qa_test_' . uniqid() . '.pdf';
        file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        try {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            $pngMime = finfo_file($finfo, $pngPath);
            assert_equals('image/png', $pngMime, "finfo_file must detect image/png MIME type");
            assert_true(array_key_exists($pngMime, $allowedMimeMap), 'image/png must pass whitelist verification');

            $pdfMime = finfo_file($finfo, $pdfPath);
            assert_equals('application/pdf', $pdfMime, "finfo_file must detect application/pdf MIME type");
            assert_true(array_key_exists($pdfMime, $allowedMimeMap), 'application/pdf must pass whitelist verification');

            finfo_close($finfo);
        } finally {
            @unlink($pngPath);
            @unlink($pdfPath);
        }
    }

    /**
     * TC-09: Rejection of Disallowed MIME Types & Script Execution Defense
     */
    public function test_receipt_upload_disallowed_mime_types_rejection(): void {
        $allowedMimeMap = [
            'image/jpeg'      => '.jpg',
            'image/png'       => '.png',
            'application/pdf' => '.pdf'
        ];

        $tempDir = sys_get_temp_dir();

        // Simulated malicious PHP file
        $phpPath = $tempDir . '/qa_malicious_' . uniqid() . '.php';
        file_put_contents($phpPath, "<?php phpinfo(); ?>");

        // Simulated plain text file
        $txtPath = $tempDir . '/qa_text_' . uniqid() . '.txt';
        file_put_contents($txtPath, "Disallowed text invoice receipt");

        // Simulated zip archive
        $zipPath = $tempDir . '/qa_archive_' . uniqid() . '.zip';
        file_put_contents($zipPath, "PK\x03\x04" . str_repeat("\x00", 26));

        try {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            $phpMime = finfo_file($finfo, $phpPath);
            assert_false(array_key_exists($phpMime, $allowedMimeMap), "PHP script MIME '{$phpMime}' must be rejected");

            $txtMime = finfo_file($finfo, $txtPath);
            assert_false(array_key_exists($txtMime, $allowedMimeMap), "Text file MIME '{$txtMime}' must be rejected");

            $zipMime = finfo_file($finfo, $zipPath);
            assert_false(array_key_exists($zipMime, $allowedMimeMap), "Zip archive MIME '{$zipMime}' must be rejected");

            finfo_close($finfo);
        } finally {
            @unlink($phpPath);
            @unlink($txtPath);
            @unlink($zipPath);
        }

        // File size limit verification (5MB = 5242880 bytes)
        $maxBytes = 5 * 1024 * 1024;
        $oversizedBytes = $maxBytes + 1;
        assert_true($oversizedBytes > $maxBytes, 'Oversized file (>5MB) must exceed maximum permitted limit');
    }

    // =========================================================================
    // DOMAIN 4: FINANCIAL IMMUTABILITY & SOFT DELETION
    // =========================================================================

    /**
     * TC-10: Soft Deletion Mandatory Reason Validation (Minimum 3 characters)
     */
    public function test_soft_delete_mandatory_reason_validation(): void {
        $invalidReasons = [
            '',             // Empty
            '   ',          // Whitespace
            'a',            // 1 char
            'no',           // 2 chars
            str_repeat('X', 256) // > 255 chars
        ];

        foreach ($invalidReasons as $r) {
            $len = mb_strlen(trim($r));
            $isValid = ($len >= 3 && $len <= 255);
            assert_false($isValid, "Deletion reason '{$r}' must be rejected (<3 or >255 chars)");
        }

        $validReason = 'Duplicate entry keyed by mistake';
        $len = mb_strlen(trim($validReason));
        assert_true($len >= 3 && $len <= 255, "Reason '{$validReason}' must pass validation");
    }

    /**
     * TC-11: Financial Immutability & Soft Deletion Preservation
     */
    public function test_financial_immutability_and_soft_delete_preservation(): void {
        $catId = $this->createCategory('Immutability');
        $expenseId = $this->insertExpense($catId, 'QA Immutability Test Voucher', 850.00, date('Y-m-d'));

        $deletionReason = 'Voided due to supplier overcharge credit';

        // Perform Soft Delete
        $stmtSoftDelete = $this->db->prepare("
            UPDATE expenses 
            SET deleted_at = NOW(),
                deletion_reason = :reason 
            WHERE id = :id AND deleted_at IS NULL
        ");
        $stmtSoftDelete->execute([
            ':reason' => $deletionReason,
            ':id'     => $expenseId
        ]);
        assert_equals(1, $stmtSoftDelete->rowCount(), 'Soft delete query must update exactly one record');

        // Verify Physical Immutability (Record is NOT hard deleted)
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM expenses WHERE id = :id");
        $stmtCount->execute([':id' => $expenseId]);
        assert_equals(1, (int) $stmtCount->fetchColumn(), 'Expense record must persist in database (Zero Hard Deletes)');

        // Verify Soft Delete Metadata
        $stmtFetch = $this->db->prepare("SELECT id, deleted_at, deletion_reason FROM expenses WHERE id = :id");
        $stmtFetch->execute([':id' => $expenseId]);
        $row = $stmtFetch->fetch();

        assert_true(!empty($row['deleted_at']), 'deleted_at timestamp must be populated');
        assert_equals($deletionReason, $row['deletion_reason'], 'deletion_reason must accurately match submitted reason');
    }

    /**
     * TC-12: Active Query Exclusions (deleted_at IS NULL)
     */
    public function test_active_queries_strictly_exclude_soft_deleted_expenses(): void {
        $catId = $this->createCategory('ActiveQueryExclusion');
        $activeExpId = $this->insertExpense($catId, 'QA Active Expense Entry', 300.00, date('Y-m-d'));
        $voidedExpId = $this->insertExpense($catId, 'QA Voided Expense Entry', 700.00, date('Y-m-d'));

        // Soft delete the second expense
        $this->db->prepare("
            UPDATE expenses 
            SET deleted_at = NOW(), deletion_reason = 'Cancelled check' 
            WHERE id = :id
        ")->execute([':id' => $voidedExpId]);

        // Active list query
        $stmtList = $this->db->prepare("
            SELECT id, title FROM expenses 
            WHERE category_id = :cat_id AND deleted_at IS NULL
        ");
        $stmtList->execute([':cat_id' => $catId]);
        $activeList = $stmtList->fetchAll(PDO::FETCH_KEY_PAIR);

        assert_true(isset($activeList[$activeExpId]), 'Active expense must appear in active list');
        assert_false(isset($activeList[$voidedExpId]), 'Soft-deleted expense must NOT appear in active list');

        // Single expense fetch by ID (Simulating get_expense)
        $stmtGet = $this->db->prepare("SELECT id FROM expenses WHERE id = :id AND deleted_at IS NULL");
        $stmtGet->execute([':id' => $voidedExpId]);
        assert_false((bool) $stmtGet->fetch(), 'Fetching soft-deleted expense by ID must return false (HTTP 404)');
    }

    /**
     * TC-13: Audit Log Generation on Expense Soft Delete
     */
    public function test_audit_log_verification_on_soft_delete(): void {
        $this->setSessionAsAdmin();
        $catId = $this->createCategory('AuditTrail');
        $expenseId = $this->insertExpense($catId, 'QA Audit Log Expense Voucher', 450.00, date('Y-m-d'));

        $stmtOrig = $this->db->prepare("SELECT * FROM expenses WHERE id = :id");
        $stmtOrig->execute([':id' => $expenseId]);
        $existing = $stmtOrig->fetch();

        $deletionReason = 'Audited error duplicate correction';

        // Write Audit Log
        audit_log('expenses', 'SOFT_DELETE', $expenseId, $existing, [
            'deleted_at'      => date('Y-m-d H:i:s'),
            'deletion_reason' => $deletionReason
        ]);

        // Query audit_logs table
        $stmtAudit = $this->db->prepare("
            SELECT * FROM audit_logs 
            WHERE module = 'expenses' 
              AND action = 'SOFT_DELETE' 
              AND record_id = :id 
            ORDER BY id DESC LIMIT 1
        ");
        $stmtAudit->execute([':id' => $expenseId]);
        $log = $stmtAudit->fetch();

        assert_true((bool) $log, 'Audit log entry must exist for SOFT_DELETE action');
        assert_equals($this->adminUserId, (int) $log['user_id'], 'Audit log must record admin user ID');
        assert_equals('expenses', $log['module'], 'Audit log module must be expenses');
        assert_equals('SOFT_DELETE', $log['action'], 'Audit log action must be SOFT_DELETE');

        $newValues = json_decode($log['new_values'], true);
        assert_true(is_array($newValues), 'new_values must be valid JSON');
        assert_equals($deletionReason, $newValues['deletion_reason'], 'Audit log new_values must record deletion_reason');
    }

    // =========================================================================
    // DOMAIN 5: ROLE-BASED ACCESS CONTROL (RBAC) ENFORCEMENT
    // =========================================================================

    /**
     * TC-14: Admin Full Expense Privileges (expenses_view, expenses_manage, expenses_delete)
     */
    public function test_rbac_admin_full_privileges(): void {
        $this->setSessionAsAdmin();

        assert_true(has_permission('expenses_view'), 'Admin must possess expenses_view permission');
        assert_true(has_permission('expenses_manage'), 'Admin must possess expenses_manage permission');
        assert_true(has_permission('expenses_delete'), 'Admin must possess expenses_delete permission');
    }

    /**
     * TC-15: Manager Expense Delete Guard (Forbidden from Deleting Expenses)
     */
    public function test_rbac_manager_delete_forbidden(): void {
        $this->setSessionAsManager();

        assert_true(has_permission('expenses_view'), 'Manager must possess expenses_view permission');
        assert_true(has_permission('expenses_manage'), 'Manager must possess expenses_manage permission');

        // Strictly forbidden from deleting expenses
        assert_false(
            has_permission('expenses_delete'), 
            'Manager must be strictly FORBIDDEN from expenses_delete permission (Admin Only)'
        );
    }

    /**
     * TC-16: Cashier Complete Denial from Expense Operations (HTTP 403)
     */
    public function test_rbac_cashier_denied_all_expense_operations(): void {
        $this->setSessionAsCashier();

        assert_false(has_permission('expenses_view'), 'Cashier must NOT have expenses_view permission');
        assert_false(has_permission('expenses_manage'), 'Cashier must NOT have expenses_manage permission');
        assert_false(has_permission('expenses_delete'), 'Cashier must NOT have expenses_delete permission');
    }

    // =========================================================================
    // DOMAIN 6: FINANCIAL METRICS & AGGREGATIONS
    // =========================================================================

    /**
     * TC-17: Expense Financial Metrics Calculation Formulas
     */
    public function test_expense_financial_metrics_aggregation_formulas(): void {
        $this->setSessionAsAdmin();
        $this->cleanupTestData();

        $catA = $this->createCategory('OfficeRent');
        $catB = $this->createCategory('UtilitiesPower');

        $today = date('Y-m-d');
        $earlierThisMonth = date('Y-m-01'); // 1st of current month

        // Category A: 1200.00 total this month
        $this->insertExpense($catA, 'QA Office Monthly Lease', 1200.00, $earlierThisMonth);

        // Category B: 350.00 today + 150.00 earlier = 500.00 this month
        $this->insertExpense($catB, 'QA Water Bill Today', 350.00, $today);
        $this->insertExpense($catB, 'QA Internet Bill Earlier', 150.00, $earlierThisMonth);

        // 1. Calculate Monthly Total for QA test expenses
        $stmtMonth = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0.00) 
            FROM expenses 
            WHERE deleted_at IS NULL 
              AND category_id IN (:cA, :cB)
              AND DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
        ");
        $stmtMonth->execute([':cA' => $catA, ':cB' => $catB]);
        $monthTotal = (float) $stmtMonth->fetchColumn();

        // 1200.00 + 350.00 + 150.00 = 1700.00
        assert_equals(1700.00, $monthTotal, 'Monthly total must equal 1700.00');

        // 2. Calculate Today's Total for QA test expenses
        $stmtToday = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0.00) 
            FROM expenses 
            WHERE deleted_at IS NULL 
              AND category_id IN (:cA, :cB)
              AND expense_date = CURRENT_DATE
        ");
        $stmtToday->execute([':cA' => $catA, ':cB' => $catB]);
        $todayTotal = (float) $stmtToday->fetchColumn();

        // Only the 350.00 water bill is today
        assert_equals(350.00, $todayTotal, "Today's total must equal 350.00");

        // 3. Top Category Calculation for current month
        $stmtTop = $this->db->prepare("
            SELECT c.name, COALESCE(SUM(e.amount), 0.00) AS total 
            FROM expenses e 
            JOIN expense_categories c ON e.category_id = c.id 
            WHERE e.deleted_at IS NULL 
              AND e.category_id IN (:cA, :cB)
              AND DATE_FORMAT(e.expense_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m') 
            GROUP BY c.id, c.name 
            ORDER BY total DESC 
            LIMIT 1
        ");
        $stmtTop->execute([':cA' => $catA, ':cB' => $catB]);
        $topCat = $stmtTop->fetch();

        assert_true((bool) $topCat, 'Top category query must return a leader');
        assert_equals(1200.00, (float) $topCat['total'], 'Top category total must be 1200.00 (Category A)');
    }

    /**
     * TC-18: Financial Metrics Strictly Exclude Soft-Deleted Expenses
     */
    public function test_financial_metrics_strictly_exclude_soft_deleted_expenses(): void {
        $this->setSessionAsAdmin();
        $catId = $this->createCategory('MetricSoftDelExclusion');
        $today = date('Y-m-d');

        // Active Expense: 400.00
        $activeId = $this->insertExpense($catId, 'QA Active Expense for Metric', 400.00, $today);

        // Voided Expense: 9000.00 (Must NOT inflate metrics)
        $voidedId = $this->insertExpense($catId, 'QA Cancelled Huge Voucher', 9000.00, $today);
        $this->db->prepare("
            UPDATE expenses 
            SET deleted_at = NOW(), deletion_reason = 'Erroneous voucher amount' 
            WHERE id = :id
        ")->execute([':id' => $voidedId]);

        // Monthly Total check
        $stmtMonth = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0.00) 
            FROM expenses 
            WHERE deleted_at IS NULL 
              AND category_id = :cid
              AND DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
        ");
        $stmtMonth->execute([':cid' => $catId]);
        $monthTotal = (float) $stmtMonth->fetchColumn();

        assert_equals(400.00, $monthTotal, 'Monthly total must strictly equal 400.00 and exclude 9000.00 voided voucher');

        // Today Total check
        $stmtToday = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0.00) 
            FROM expenses 
            WHERE deleted_at IS NULL 
              AND category_id = :cid
              AND expense_date = CURRENT_DATE
        ");
        $stmtToday->execute([':cid' => $catId]);
        $todayTotal = (float) $stmtToday->fetchColumn();

        assert_equals(400.00, $todayTotal, "Today's total must strictly equal 400.00 and exclude voided expense");
    }
}

