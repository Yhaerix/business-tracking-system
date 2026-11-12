# Project Requirements Document (PRD)

## 1. Executive Summary & Purpose
- **System Name**: Small Business Sales and Expense Tracking System
- **Objective**: Provide small business owners and managers with an intuitive, reliable web platform to track daily cash flow, record sales and operational expenses, manage stock levels, process customer orders (including Cash on Delivery - COD), issue receipts and invoices, and evaluate net financial performance across customizable time horizons.
- **Scope**: Single-business entity deployment with role-segregated access, local-first deployment on XAMPP/Apache with MySQL, optimized for desktop and mobile-responsive browser access.

---

## 2. User Roles & Stakeholder Personas
The system defines three core roles under Role-Based Access Control (RBAC):

1. **Business Owner / Administrator (`admin`)**:
   - Full system access.
   - Manages users, assign roles, inspects complete audit logs, updates system settings.
   - Views complete financial reports, net income, profit margins, and exports data.
2. **Store Manager (`manager`)**:
   - Oversees daily store operations, inventory stock-ins/adjustments, and expense approvals.
   - Monitors COD deliveries, reconciles collected cash, and reviews sales summaries.
   - Cannot create/delete users or wipe audit records.
3. **Cashier / Sales Clerk (`cashier`)**:
   - Records point-of-sale transactions and registers incoming customer orders.
   - Updates order statuses (e.g., dispatch, payment collected on delivery).
   - Generates and prints customer receipts.
   - Has restricted access: cannot view business net income, edit historic expenses, or adjust user roles.

---

## 3. Functional Requirements (By Domain)

### 3.1. Sales & Revenue Management
- **POS / Sales Entry**: Record counter and customer sales with item lookup, quantity, unit price, and discounts.
- **Payment Method**: Strictly Cash on Delivery (`COD`).
- **Totals Calculation**: Automatic subtotal, discount deductions, and final grand total (no tax calculations).
- **Transaction History**: Searchable and filterable transaction log by date, cashier, status, and invoice number.

### 3.2. Orders & Cash on Delivery (COD) Management
- **Order Lifecycle Tracking**: Support explicit states: `Pending` $\rightarrow$ `Confirmed` $\rightarrow$ `Dispatched / Out for Delivery` $\rightarrow$ `Delivered` $\rightarrow$ `Paid` (or `Cancelled` / `Returned`).
- **COD Collection Workflow**: Dedicated queue for tracking pending cash collection from couriers/drivers, marking payments as reconciled upon physical cash handover.
- **Customer & Delivery Details**: Record recipient name, phone, delivery address, and delivery notes.

### 3.3. Business Expense Management
- **Expense Recording**: Capture operational expenses (e.g., rent, utilities, inventory purchase, transport, marketing).
- **Categorization**: Multi-level or customizable expense categories (e.g., Cost of Goods, Operational, Payroll).
- **Receipt Attachments**: Ability to upload scanned receipts or photo proofs (`.pdf`, `.jpg`, `.png`).
- **Approval Workflow**: Expenses above a threshold require Manager or Owner sign-off.

### 3.4. Inventory & Stock Control
- **Product Catalog**: SKU, product name, category, cost price, selling price, current stock quantity, and reorder threshold level.
- **Stock Movement Ledger**: Every inventory change is recorded with a type: `INITIAL`, `SALE`, `RESTOCK`, `DAMAGE/LOSS`, `RETURN`.
- **Low Stock Alerts**: Real-time visual badge and dashboard alert when stock falls below the threshold.
- **Negative Stock Prevention**: Disallow order completion if available stock is insufficient.

### 3.5. Invoicing & Receipt Generation
- **Printable Receipts**: Compact, thermal-printer friendly (80mm/58mm) or standard receipt layout.
- **Formal Invoices**: A4 printable and downloadable PDF invoices with business header, invoice number, itemized table, and payment status.
- **Sequential Numbering**: Formatted auto-incrementing serials (e.g., `INV-2026-0001`, `RCP-2026-0001`).

### 3.6. Financial Reporting & Performance Analytics
- **Calculations**:
  - `Gross Sales = Total Paid & Delivered Sales`
  - `Total Expenses = Sum of Verified Operating Expenses`
  - `Net Income = Gross Sales - Total Expenses`
- **Period Views**: Daily, weekly, monthly, quarterly, and custom date range summaries.
- **Trend Charts**: Visual sales vs. expenses comparisons and top-selling products.

### 3.7. Filtering, Search & Export
- **Multi-Filter Engine**: Filter any table by date range, category, order/delivery status, or user.
- **Data Export**: One-click export to CSV and printable PDF for sales summaries, expense sheets, and inventory valuation.

### 3.8. Notification & Internal Communication System
- **In-App Notifications**: Low-stock alerts, pending COD collections, and new order assignments.
- **Order Notes / Communication**: Internal timeline comments on order records between cashiers and managers (e.g., "Customer requested delivery after 3 PM").

### 3.9. Audit Logs & System Activity
- Immutable activity log capturing user, timestamp, IP address, module, action (`CREATE`, `UPDATE`, `VOID`, `LOGIN`), and changed values.

---

## 4. Non-Functional Requirements
- **Performance**: Dashboard and standard list queries must load in $< 500\text{ms}$ on local XAMPP environment.
- **Data Integrity**: Zero financial deletion; 100% atomic database transactions on stock-affecting sales.
- **Security**: OWASP Top 10 compliance: CSRF tokens, strict XSS escaping, prepared statements, secure session lifecycle.
- **Usability**: Fully responsive, clean interface usable on mobile tablets at checkout counters and desktop browsers.
