# Changelog

All notable changes to the **Small Business Sales and Expense Tracking System** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.8.0] - 2026-11-11
### Added
- **Phase 7: Role-Based Access Control, Audit Logs & System Hardening Complete**:
  - `modules/users/index.php`: Complete responsive user administration ledger with top KPI stat cards (Total Accounts, Active Users, Suspended Accounts, Current Session indicator), search & role filter bar, user cards with role and status badges, lockout/failed login indicators, and interactive modals:
    - **Add New User Modal**: Username (3-30 chars validation), full name, operational role select, password confirmation, Bcrypt disclosure notice.
    - **Edit User Modal**: Full name, role select, active/suspended status toggle, with automated active-session lockout protection (`CURRENT_USER_ID === user.id` guards disabling self-deactivation and self-demotion).
    - **Reset Password Modal**: Dedicated credential reset interface with automated account unlocking and failed login counter clearance.
  - `modules/users/handler.php`: Backend user administration API guarded by `require_permission('users_manage')`, CSRF validation, Bcrypt cost 12 hashing (`password_hash`), duplicate username rejection (422), self-deactivation & self-demotion rejection (400), and credential-redacted audit logging (`password` and `password_hash` omitted).
  - `modules/audit/index.php`: Searchable, responsive audit trail viewer with top KPI cards (Total Audit Events, Today's Activity, Record Mutations, Security & Auth Events), multi-axis filter toolbar (Date From, Date To, Module select, Action select, Operator select, and keyword search), paginated records table with color-coded action badges, and **Inspect Diff Modal**:
    - Metadata grid (timestamp, actor with role, module/action, record ID, IP address, user-agent).
    - Intelligent payload diff visualizer displaying field-by-field modifications with highlight badges (`.val-old` vs `.val-new`).
    - Expandable raw JSON terminal view for full compliance inspections.
  - `modules/audit/handler.php`: Backend audit querying API guarded by `require_permission('audit_view')` (Admin and Manager permitted; Cashier denied), parameterized prepared queries with zero SQL concatenation, multi-filter pagination (`page`, `limit`), and JSON payload decoding.
  - `tests/Integration/Phase7_Verification_Test.php`: 11 comprehensive automated integration tests covering user creation, duplicate prevention, Bcrypt cost 12, self-protection guards, password reset lockout clearance, audit log redaction, query filtering, JSON diff structure, RBAC matrix, CSRF validation, and backend handler file integrity.
  - Test suite expanded to **72/72 tests passing cleanly at 100% green**.

---

## [0.7.0] - 2026-11-11
### Added
- **Phase 6: Financial Reports & Dashboard KPIs Complete**:
  - `modules/dashboard/index.php`: Revamped real-time operational dashboard with live KPI cards (Today's Realized Sales, Pending COD Collection, Physical Cash on Hand from Remitted COD, Low Stock Alerts, and Today's Operating Expenses & Net Income for Admin/Manager). Integrated critical low-stock alert banner and recent 5 orders and expenses.
  - `modules/reports/index.php`: Financial performance reports interface with date range presets (Today, This Week, This Month, This Year, Custom Range), comprehensive KPI summary cards (Realized Gross Sales, Total Operating Costs, Net Income / Profit Margin, Remitted Cash vs Unremitted COD Cash in Courier Hands), and dual breakdown views (Expenses by Category with visual proportion bars, and Daily Cash Flow & Net Margin timeline).
  - `modules/reports/handler.php`: Backend reporting API supporting `financial_summary` and low-memory streaming `export_csv` using `php://output` and UTF-8 BOM encoding for Microsoft Excel compatibility.
  - Strict financial mathematical invariants:
    - Realized Gross Sales: Strictly delivered orders only (`order_status = 'DELIVERED'`).
    - Operating Expenses: Active expenses only (`deleted_at IS NULL`).
    - Net Income: $\text{Realized Gross Sales} - \text{Total Operating Expenses}$.
    - Zero Tax: Enforced 0.00% tax calculation system-wide.
  - Role-Based Access Control: Admin and Manager have full reporting and export authority; Cashier role is strictly denied access to `reports_view`, `reports_net_income`, and `reports_export`.
  - `tests/Integration/Phase6_Verification_Test.php`: 8 new integration test cases verifying realized sales formulas, soft-delete exclusions, net income math, remitted cash flow tracking, low stock detection, CSV export columns, and RBAC denial.
  - Complete automated test suite expanded to **61/61 tests passing cleanly at 100% green**.

---

## [0.6.0] - 2026-11-11
### Added
- **Phase 5: Invoicing, Receipts & Printable Layouts Complete**:
  - `modules/invoices/index.php`: Invoices and receipts ledger view with 4 top KPI cards (Total Invoiced, Paid / Collected, Pending Collection, Void Documents), multi-filter search (by Invoice #, Receipt #, Order #, Customer, Date range, and Status), responsive table, pagination, and direct links to printable document views.
  - `modules/invoices/view.php`: Dual-format document presentation view with dynamic format switcher (Formal A4 Sales Invoice and 80mm Thermal POS Receipt), zero-tax breakdown, live COD collection status indicators, order line items, printable button (`window.print()`), and Administrative Void workflow modal.
  - `modules/invoices/handler.php`: Backend API endpoints supporting `list_invoices` (with automated status synchronization), `get_invoice` (with atomic lazy generation of sequential `INV-YYYY-XXXX` and `RCP-YYYY-XXXX` identifiers), and `void_invoice` (requiring mandatory justification note and audit logging).
  - Enhanced database schema: added `void_reason VARCHAR(255) NULL` and `updated_at TIMESTAMP` columns to `invoices` table.
  - `assets/css/print.css`: Enhanced print stylesheets with `@media print` rules cleanly suppressing UI chrome (sidebar, navbar, control buttons, status banners), isolating active document format (A4 vs 80mm thermal), and ensuring proper page-break formatting.
  - `tests/Integration/Phase5_Verification_Test.php`: 10 comprehensive automated integration tests covering sequential numbering formats, lazy generation, idempotency, zero-tax math invariants, payment status synchronization, void workflows, audit logging, RBAC matrix, query filtering, and CSS selectors.
  - Full automated test suite passing cleanly with **53/53 tests green** (100% pass rate).
### Added
- **Phase 4: Expense Management & Receipt Uploads Complete**:
  - `modules/expenses/index.php`: Expense records table with top 4 KPI cards (This Month's Expenses, Today's Expenses, Top Category, Total Active Records), multi-criteria filters, interactive "+ Record Expense" modal, and receipt lightview modal (image preview and PDF opener).
  - `modules/expenses/categories.php`: Expense categories CRUD view with expense count, total amount spent, edit/create modals, and default category protection.
  - `modules/expenses/handler.php`: Backend API endpoints supporting `list_expenses`, `get_expense`, `create_expense`, `update_expense`, `delete_expense`, `list_categories`, `create_category`, `update_category`, and `delete_category`.
  - Added `deletion_reason` column to `expenses` table in `docs/schema.sql` and database.
  - Double-barrier upload security: server-side `finfo_file()` MIME whitelist validation (`image/jpeg`, `image/png`, `application/pdf`), 5MB size limit, random hashed filenames in `uploads/receipts/`, and `uploads/.htaccess` preventing script execution.
  - Financial immutability: zero hard deletes on expenses; soft deletion requires mandatory reason (min 3 characters) and writes to `audit_logs`.
  - Role-Based Access Control: Admin has full view/manage/delete privileges; Store Manager can view and manage but is strictly denied deletion (HTTP 403); Cashier is completely denied from all expense endpoints.
  - Category deletion protection: blocks deleting categories with active expenses; safely reassigns historical voided records to default Category 1 before deletion.
  - `tests/Integration/Phase4_Verification_Test.php`: 18 automated integration test cases verifying duplicate prevention, validation, MIME checks, soft delete immutability, audit logging, RBAC matrix, and financial metrics formulas.
  - All 43 system tests passing at 100% green.

---

## [0.4.0] - 2026-11-11
### Added
- **Phase 3: Sales, Orders & Cash on Delivery (COD) Workflow Complete**:
  - `modules/orders/create.php`: Split-screen POS order interface with live stock-aware product catalog, category filter pills, dynamic cart, numeric discount and delivery fee inputs, and strictly zero-tax COD total calculation (`Subtotal - Discount + Delivery Fee`).
  - `modules/orders/index.php`: Orders & Delivery dashboard with 4 top KPI metrics (Pending/Confirmed, Dispatched, Delivered, Unremitted COD Cash), multi-criteria filters, and order detail drawer.
  - `modules/orders/handler.php`: Backend API endpoints supporting `create_order`, `list_orders`, `get_order_details`, `update_status`, and `add_note`.
  - Sequential order numbering generation (`ORD-YYYY-XXXX`) with database row locks.
  - Directional COD state machine transitions (`CONFIRMED` $\rightarrow$ `DISPATCHED` $\rightarrow$ `DELIVERED` $\rightarrow$ `REMITTED`).
  - Atomic stock reservations and deduction logging (`SALE`) upon order creation.
  - Automated stock restoration via `RETURN` movement upon pre-dispatch order cancellation (`CONFIRMED` $\rightarrow$ `CANCELLED`) or courier return (`DISPATCHED` $\rightarrow$ `RETURNED`).
  - Granular RBAC enforcement blocking Cashier roles from cash remittance (`orders_remit_cod` restricted to Admin and Manager).
  - Internal order communication timeline notes feed with author attribution.
  - `tests/Integration/Phase3_Verification_Test.php`: 9 automated integration test cases verifying math, sequential numbers, stock atomicity, cancellation return, COD state progression, and RBAC remittance guards.
  - All 25 system tests passing at 100% green.

---

## [0.3.0] - 2026-11-11
### Added
- **Phase 2: Product Catalog & Inventory Management Complete**:
  - `modules/products/categories.php`: Responsive category management with item counters, add/edit/delete modals, and protection against deleting categories with active products.
  - `modules/products/index.php`: Complete product catalog table with real-time search, category filters, status filtering, stat cards, stock badges, live profit margin calculator, and add/edit modals.
  - `modules/products/handler.php`: Backend API endpoints for categories and products with CSRF verification, RBAC guards (`products_view`, `products_manage`), case-insensitive duplicate checks, soft deletes (`deleted_at`), and atomic initial stock movement logging.
  - `modules/inventory/index.php`: Low-stock alert banner, immutable stock movement ledger, search/filter controls, and manual stock adjustment modal with real-time before/after preview.
  - `modules/inventory/handler.php`: Stock movement listing and atomic stock adjustment API with `SELECT ... FOR UPDATE` concurrency locks, strict negative stock prevention, and 24-hour deduplicated low-stock alerts in `notifications`.
  - `tests/Integration/Phase2_Verification_Test.php`: 9 automated integration test cases verifying duplicate prevention, foreign key integrity, atomic movements, negative stock prevention, and low-stock alerts.
  - All 16 system tests (Phase 1 & Phase 2) passing at 100% green.

---

## [0.2.0] - 2026-11-11
### Added
- **Phase 1: Foundation, Database, UI Shell & Authentication Complete**:
  - `docs/schema.sql`: Canonical MySQL DDL migration with InnoDB, `DECIMAL(12,2)` precision, strict foreign keys (`ON DELETE RESTRICT`), and seed data for roles and default admin account.
  - `config/constants.php`: Application identity, currency (`₱`), and timezone configuration.
  - `config/database.php`: Secure PDO singleton connection pool with error shielding.
  - `includes/csrf.php`: Timing-safe CSRF token generation and validation.
  - `includes/auth.php`: Authentication guard, session regeneration, brute-force defense (5-attempt lockout), and 30-minute idle expiration.
  - `includes/rbac.php`: Master role permission matrix and `require_permission()` route guard.
  - `includes/audit.php`: Centralized `audit_log()` helper recording JSON diffs, user ID, and IP address.
  - `assets/css/main.css` & `components.css`: Modern design system tokens, typography with tabular figures, responsive grid, stat cards, badges, and modals.
  - `assets/css/print.css`: Dedicated print formatting for 80mm thermal receipts and A4 invoices.
  - `assets/js/api.js`: Central Fetch API client with automatic CSRF injection and toast alerts.
  - `includes/header.php`, `sidebar.php`, `footer.php`: Responsive, role-aware app shell layout with mobile off-canvas drawer.
  - `modules/auth/login.php` & `handler.php`: Secure login screen and authentication processor.
  - `modules/auth/logout.php`: Safe session teardown.
  - `modules/auth/403.php`: Access Denied view for unauthorized RBAC attempts.
  - `modules/dashboard/index.php`: Landing dashboard with KPI cards and quick action links.
  - `tests/Integration/AuthSecurityTest.php`: Automated test suite covering CSRF, Bcrypt hashing, and RBAC matrix. All 6 tests passing cleanly.

---

## [0.1.0] - 2026-11-11
### Added
- **Agentic Harness Governance**:
  - `AGENTS.md`: Core system identity, directory layout conventions, and engineering standards.
  - `.agents/rules/data-integrity-and-security.md`: Invariants for financial immutability, zero hard-deletes, and transaction atomicity.
  - `.agents/rules/security-requirements.md`: Password hashing standards (Bcrypt), session management, brute-force defenses, RBAC enforcement, and error shielding.
- **System Documentation & Knowledge Base**:
  - `docs/PROJECT_REQUIREMENTS.md`: Project requirements document (PRD) detailing COD-only payment model, zero-tax calculations, inventory control, and role permissions.
  - `docs/DATABASE_SCHEMA.md`: Full relational database schema specification covering users, products, inventory movements, COD orders, invoices, expenses, notes, and audit logs.
  - `docs/BUSINESS_RULES.md`: Core domain logic including Net Income formulas, COD lifecycle state machine, stock reservation rules, and invoice sequencing.
  - `docs/SYSTEM_ARCHITECTURE.md`: Technical architectural blueprints, request lifecycle sequence diagrams, standardized JSON API contracts, and memory-safe CSV streaming.
  - `docs/DEVELOPMENT_ROADMAP.md`: 7-phase step-by-step implementation plan with explicit verification gates.
