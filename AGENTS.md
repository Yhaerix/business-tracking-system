# AGENTS.md — Small Business Sales & Expense Tracking System

## 1. Project Overview & Objective
- **System**: Small Business Sales and Expense Tracking System
- **Purpose**: A web-based application designed to help small business owners monitor daily cash flow, track sales and business expenses, manage inventory/stock, process orders (including Cash on Delivery - COD), issue invoices/receipts, and generate clear financial performance reports (daily, weekly, monthly net income).
- **Architecture**: Modular PHP web application backed by MySQL, following clean separation of concerns without heavyweight external frameworks.

---

## 2. Technology Stack & Environment
- **Server Environment**: Apache / XAMPP on Windows (`c:\xampp\htdocs\Business Tracking System`)
- **Backend**: PHP 8.x (strict typing where applicable, PDO for database access)
- **Database**: MySQL / MariaDB (InnoDB engine, utf8mb4 charset, strict foreign key constraints)
- **Frontend**: Semantic HTML5, Modular CSS3, Vanilla JavaScript (Fetch API, responsive UI)
- **Reporting & Exports**: CSV / PDF export capabilities

---

## 3. Directory & Module Structure
The codebase must adhere to the following directory layout:
```text
Business Tracking System/
├── .agents/                 # Agent harness configurations (rules, skills, workflows)
│   ├── rules/               # Hard boundaries and security policies
│   └── skills/              # Reusable agent capabilities & procedures
├── assets/                  # Public static assets
│   ├── css/                 # Stylesheets
│   ├── js/                  # Client-side scripts
│   └── images/              # Media and brand icons
├── config/                  # Core system configuration
│   ├── database.php         # PDO connection & pool
│   └── constants.php        # App-wide global constants
├── docs/                    # Architecture records, database schemas, and specs
│   ├── schema.sql           # Canonical database DDL
│   └── adr/                 # Architecture Decision Records
├── includes/                # Shared utilities and layout templates
│   ├── auth.php             # Authentication & session guards
│   ├── rbac.php             # Role-Based Access Control checks
│   ├── header.php           # Common HTML header/nav
│   └── footer.php           # Common HTML footer
├── modules/                 # Self-contained business domains
│   ├── auth/                # Login, logout, session management
│   ├── dashboard/           # Financial summary cards & quick metrics
│   ├── sales/               # Sales records, order entry, COD tracking
│   ├── expenses/            # Business expenses & category management
│   ├── inventory/           # Stock levels, movements, and alerts
│   ├── invoices/            # Invoicing, receipts, and printable templates
│   ├── reports/             # Daily, weekly, monthly financial summaries
│   ├── audit/               # Activity logs and audit trails
│   └── users/               # User management and role assignment
└── tests/                   # Verification and test suites
```

---

## 4. Engineering & Code Standards for Agents
1. **Database Access**:
   - Always use `PDO` with parameterized prepared statements. **Never** concatenate variables directly into SQL queries.
   - Use transactions (`beginTransaction`, `commit`, `rollBack`) for multi-step mutations (e.g., deducting inventory while creating a sales record).
2. **Security & Input Handling**:
   - Sanitize all incoming `$_POST`, `$_GET`, and `$_REQUEST` payloads.
   - Escape output with `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` to prevent XSS.
   - Guard every authenticated route with session validation and RBAC checks (`check_permission(...)`).
3. **Data Integrity & Auditability**:
   - All critical actions (creation, status update, deletion) must write an event to the `audit_logs` table.
   - Soft deletes (`deleted_at IS NULL`) are preferred for financial transactions and order records.
4. **Agent Workflow Discipline**:
   - Never implement code without verifying existing database schema and module boundaries first.
   - When introducing changes, update corresponding documentation in `docs/`.
