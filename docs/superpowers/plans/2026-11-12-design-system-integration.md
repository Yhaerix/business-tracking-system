# UI/UX Design System Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Seamlessly integrate the modernized UI components, styles, layouts, and interactive modals from `new-design.html` into the main PHP web application while 100% preserving all backend logic, database integrity, financial formulas, security rules, and RBAC policies.

**Architecture:** Maintain strict separation of concerns: PHP views in `modules/` render server-side data and consume modern CSS classes from `assets/css/`; client interactions use `assets/js/main.js` and `assets/js/api.js`; backend handlers in `modules/*/handler.php` remain strictly JSON-producing endpoints with PDO transactions and CSRF checks.

**Tech Stack:** PHP 8.x (PDO, strict types), MySQL/MariaDB (InnoDB, transactions), Semantic HTML5, Vanilla JavaScript (ES6+, Fetch API), Modular CSS3 (Design tokens).

**Spec References:**
- Canonical Prototype: `new-design.html`
- Architecture & Guidelines: `AGENTS.md`, `docs/SYSTEM_ARCHITECTURE.md`, `docs/UI_DESIGN_SPEC.md`
- Data Integrity Rules: `.agents/rules/data-integrity-and-security.md`
- Security Standards: `.agents/rules/security-requirements.md`

---

## Global Constraints & Critical Invariants

1. **Zero Backend Regression**: Do not alter database schemas, PDO query parameterization, or transactional atomicity.
2. **Financial Data Immutability**: No hard `DELETE` statements on `orders`, `sales`, `expenses`, `invoices`, or `payments`. Always preserve soft deletes (`deleted_at IS NULL`) with audit trails.
3. **5-Stage COD State Machine**: Maintain the exact transition flow: `PENDING` $\rightarrow$ `CONFIRMED` (stock deducted) $\rightarrow$ `DISPATCHED` $\rightarrow$ `DELIVERED` (cash collected) $\rightarrow$ `REMITTED` (cash handed to store).
4. **Calculations**: Net Income strictly $= \text{Realized Gross Sales (Delivered \& Collected)} - \text{Total Operating Expenses}$. Tax is strictly 0%.
5. **Security & RBAC**: Every state-mutating form/action must submit and validate a valid CSRF token; all routes must enforce `require_login()` and `require_permission()`; all dynamic variables must be escaped with `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')`.
6. **MIME Verification**: Expense receipt uploads must strictly verify MIME via `finfo_file()` (`image/jpeg`, `image/png`, `application/pdf`).

---

## Backend Features to Preserve by Module

| Module | Backend Features & Business Invariants to Preserve |
| :--- | :--- |
| **Foundation & Auth** (`modules/auth/`, `includes/`) | - `password_verify` with BCRYPT cost 12<br>- Brute-force lockout (5 failed attempts = 15-minute lock)<br>- Session fixation prevention (`session_regenerate_id(true)`)<br>- CSRF token generation and `hash_equals()` validation<br>- Role-based view guards (`has_permission`, `check_permission`) |
| **Dashboard** (`modules/dashboard/`) | - Real-time calculation of today's realized sales (`DELIVERED` today)<br>- Pending COD collections count and sum (`CONFIRMED`, `DISPATCHED`)<br>- Cash on Hand strictly from `REMITTED` payments<br>- Low stock query (`current_stock <= low_stock_threshold`)<br>- Cashier-restricted dashboard view |
| **Orders & COD** (`modules/orders/`) | - POS cart calculations: Subtotal - Discount + Delivery Fee = Total (No tax)<br>- Stock deduction inside PDO transaction on `CONFIRMED`<br>- Automatic stock restoration (`RETURN` movement) on pre-dispatch cancellation<br>- Payment collection state update on `DELIVERED`<br>- Physical cash remittance validation on `REMITTED` |
| **Products & Inventory** (`modules/products/`, `modules/inventory/`) | - SKU uniqueness validation<br>- Insufficient stock rejection on deductions (no negative stock)<br>- Immutable audit ledger in `inventory_movements` for every restock, sale, adjustment, or return |
| **Expenses** (`modules/expenses/`) | - Server-side MIME verification and safe filename hashing for receipts<br>- Soft deletion requiring user reason note<br>- Category foreign key integrity |
| **Invoices & Receipts** (`modules/invoices/`) | - Sequential numbering generators (`INV-YYYY-XXXX`, `RCP-YYYY-XXXX`)<br>- 80mm POS thermal print CSS media formatting<br>- Standard A4 print styling |
| **Reports** (`modules/reports/`) | - Realized sales vs Operating expenses Net Income formula<br>- Date range boundary filtering (`today`, `this_week`, `this_month`, custom)<br>- Low-memory streaming CSV export via `php://output` |
| **Users & Audit** (`modules/users/`, `modules/audit/`) | - Admin privilege invariant (prevents self-demotion or self-deactivation)<br>- Password reset with lockout counter reset<br>- Complete audit trail logging with JSON old/new diffs |

---

## Files Identified for Modification

### 1. Stylesheets & Global Assets
- `assets/css/main.css`: Add missing CSS variables and layout utilities from `new-design.html`.
- `assets/css/components.css`: Add component classes: `.cod-stepper`, `.step-node`, `.order-detail-grid`, `.card-stat`, `.stock-preview-box`, `.document-preview-wrapper`, `.notif-panel`, `.profile-menu`, `.badge-*`.
- `assets/js/main.js`: Integrate global UI controllers for sidebar collapse, notifications dropdown, persona switch (demo/active), modal opening/closing, and chart rendering.

### 2. Global Layout Shell
- `includes/header.php`: Integrate topbar notification dropdown, breadcrumb styling, and profile chip.
- `includes/sidebar.php`: Polish navigation icons, active states, and collapse toggle button.
- `includes/footer.php`: Add global toast container and common modal backdrops if needed.

### 3. Module Views & Modals
- `modules/dashboard/index.php`: Match View 1 (KPI stat cards, sales trend chart, low-stock banner, cashier quick links).
- `modules/orders/create.php`: Match View 2 (POS layout: product search/category filter on left, COD cart & customer details on right).
- `modules/orders/index.php`: Match View 3 & Modals 1, 2, 3, 12 (orders filter tabs, 5-stage COD drawer stepper, delivery confirmation modal, cancellation modal, cash remittance modal).
- `modules/products/index.php`: Match View 4 & Modal 4 (product grid/table, add/edit product modal, stock badges).
- `modules/products/categories.php`: Match View 5 & Modal 7 (category management cards and CRUD modal).
- `modules/inventory/index.php`: Match View 6 & Modal 5 (stock movement ledger, manual stock adjustment modal).
- `modules/expenses/index.php`: Match View 7 & Modals 6, 10 (expenses table, record expense modal, receipt lightbox modal).
- `modules/expenses/categories.php`: Match View 8 & Modal 7 (expense categories budget cards & CRUD modal).
- `modules/invoices/index.php`: Match View 9 (invoices list, status badges, action dropdowns).
- `modules/invoices/view.php`: Match View 10 (toggleable document view: A4 formal invoice vs 80mm thermal receipt).
- `modules/reports/index.php`: Match View 11 (financial report KPI cards, net income bar chart, breakdowns, CSV export).
- `modules/users/index.php`: Match View 12 & Modals 8, 11 (user table, role badges, create/edit modal, password reset modal).
- `modules/audit/index.php`: Match View 13 & Modal 9 (audit trail table, JSON diff viewer modal).
- `modules/auth/login.php`: Match View 14 (clean login card layout with tokens).
- `modules/auth/403.php`: Match View 15 (styled 403 Forbidden card with return button).

---

## Phased Implementation Plan

### Phase 1: Design System Foundations & App Shell
- [ ] **Task 1.1**: Merge tokens and component styles from `new-design.html` into `assets/css/main.css` and `assets/css/components.css`.
- [ ] **Task 1.2**: Update `assets/js/main.js` with robust modal, drawer, toast, and dropdown handlers.
- [ ] **Task 1.3**: Sync `includes/header.php`, `includes/sidebar.php`, and `includes/footer.php` with the prototype app shell.
- [ ] **Verification**: Open browser at `modules/dashboard/index.php`; verify responsive sidebar collapse, profile dropdown, and notification panel.

### Phase 2: POS & Orders Management (COD State Machine)
- [ ] **Task 2.1**: Update `modules/orders/create.php` to match View 2 (interactive product picker and live COD cart).
- [ ] **Task 2.2**: Update `modules/orders/index.php` to match View 3 with status filter tabs and action menus.
- [ ] **Task 2.3**: Integrate Modals 1, 2, 3, and 12 into `modules/orders/index.php` (COD 5-stage stepper drawer, deliver prompt, cancel prompt, remittance prompt).
- [ ] **Verification**: Create a COD order, transition through Dispatched $\rightarrow$ Delivered $\rightarrow$ Remitted, and verify stock deduction and cash remittance.

### Phase 3: Catalog, Inventory & Expenses
- [ ] **Task 3.1**: Update `modules/products/index.php` & `modules/products/categories.php` to match Views 4 & 5.
- [ ] **Task 3.2**: Update `modules/inventory/index.php` to match View 6 with manual stock adjustment modal (Modal 5).
- [ ] **Task 3.3**: Update `modules/expenses/index.php` & `modules/expenses/categories.php` to match Views 7 & 8 with receipt lightbox modal (Modal 10).
- [ ] **Verification**: Add product, adjust stock, record expense with image upload, preview receipt image in lightbox.

### Phase 4: Invoices, Reports & Dashboard Visualizations
- [ ] **Task 4.1**: Update `modules/invoices/index.php` & `modules/invoices/view.php` to match Views 9 & 10 (A4 and 80mm preview toggles).
- [ ] **Task 4.2**: Update `modules/reports/index.php` to match View 11 (financial charts and filters).
- [ ] **Task 4.3**: Finish `modules/dashboard/index.php` to match View 1 (sales trend chart, quick actions, KPI cards).
- [ ] **Verification**: Verify print layout preview (`Ctrl + P`), test CSV export, and verify dashboard charts.

### Phase 5: Administration, Security & Polish
- [ ] **Task 5.1**: Update `modules/users/index.php` to match View 12 and Modals 8 & 11.
- [ ] **Task 5.2**: Update `modules/audit/index.php` to match View 13 and Modal 9 (JSON diff modal).
- [ ] **Task 5.3**: Polish `modules/auth/login.php` (View 14) and `modules/auth/403.php` (View 15).
- [ ] **Verification**: Log in as each role (`admin`, `manager`, `cashier`), inspect audit diffs, and confirm RBAC restrictions.

