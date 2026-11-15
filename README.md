# Small Business Sales & Expense Tracking System

A modern, responsive web application designed for small business owners and retail operators to monitor daily cash flow, record sales and COD orders, manage stock levels, track operational expenses, and generate real-time financial performance reports.

---

## 🚀 Key Features

- **Dashboard & Live Analytics**: Realized gross sales, COD pending collection, inventory valuation, stock health metrics, and interactive ApexCharts sales vs. expenses trajectory.
- **Point of Sale (POS) & Order Management**: Rapid product catalog selection, instant search, stock tag validation, and Cash-on-Delivery (COD) order lifecycle pipeline (`Confirmed` → `Dispatched` → `Delivered` → `Remitted`).
- **Inventory & Stock Movements**: Multi-item atomic inventory mutations, automated low-stock warnings, and an immutable stock ledger tracking restocks, sales, damages, and returns.
- **Expense Tracking**: Category-based expense recording, receipt attachment support (PNG, JPG, PDF), and audit trails for soft deletions.
- **Financial Reports & Analytics**: 7-day performance trajectory, category expense mix donut chart, cash flow net margin tables, and low-memory CSV data export.
- **Invoices & Receipts**: Printable A4 and 80mm thermal receipt templates with status tracking.
- **Audit Trail & Inspection**: Complete immutable logging of all state mutations with a visual Before → After change inspection drawer.
- **Internal Team Chat**: Slide-out drawer with real-time polling, direct messaging, order attachments, and store-wide channels.
- **Role-Based Access Control (RBAC)**: Distinct permissions for `Admin` (Business Owner), `Manager`, and `Cashier`.

---

## 🛠️ Technology Stack

- **Server Environment**: Apache / XAMPP (Windows / Linux / macOS)
- **Backend**: PHP 8.x (Strict typing, PDO prepared statements)
- **Database**: MySQL 8.x / MariaDB (InnoDB, `utf8mb4_unicode_ci` charset, foreign key integrity)
- **Frontend**: Semantic HTML5, Modular CSS3, Vanilla JavaScript (Fetch API)
- **Visualizations**: ApexCharts (Responsive dual-series line/area & donut charts)

---

## 📋 Installation & Setup Guide

### 1. Prerequisites
- [XAMPP](https://www.apachefriends.org/) (with PHP 8.1+ and MySQL/MariaDB)

### 2. Download or Clone the Repository
Clone or place the project folder into your web server's root directory:
```bash
cd c:/xampp/htdocs
git clone https://github.com/YOUR_USERNAME/Business-Tracking-System.git "Business Tracking System"
```

### 3. Start Web Server
Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.

### 4. Database Setup
1. Open [phpMyAdmin](http://localhost/phpmyadmin) in your browser.
2. Create a new database named `business_tracking_db` (or run `docs/schema.sql`).
3. Click the **Import** tab, choose `docs/schema.sql`, and click **Import** (or **Go**).

*Alternatively, run the one-line terminal setup:*
```powershell
php -r "$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', ''); $pdo->exec('CREATE DATABASE IF NOT EXISTS business_tracking_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'); $pdo->exec('USE business_tracking_db;'); $pdo->exec(file_get_contents('docs/schema.sql')); echo 'Database successfully imported!';"
```

### 5. Access the Application
Open your browser and navigate to:
```text
http://localhost/Business%20Tracking%20System/
```

---

## 🔐 Default Credentials

| Role | Username | Password | Access Privileges |
| :--- | :--- | :--- | :--- |
| **Business Owner (Admin)** | `admin` | `admin123` | Full access to settings, financials, user management, and audit logs. |

---

## 📂 Directory Layout

```text
Business Tracking System/
├── assets/                  # Public static assets (CSS, JS, Images, ApexCharts)
│   ├── css/                 # Modern design stylesheets
│   ├── js/                  # Client controllers and API utilities
│   └── images/              # System logos and icons
├── config/                  # Core system configuration
│   ├── database.php         # PDO connection pool
│   └── constants.php        # App-wide global constants
├── docs/                    # Database DDL
│   └── schema.sql           # Canonical database schema & seeds
├── includes/                # Shared layout templates and auth guards
│   ├── auth.php             # Session management
│   ├── rbac.php             # Role-based access control
│   ├── csrf.php             # Cross-Site Request Forgery defenses
│   ├── header.php           # Global topbar & navigation
│   ├── sidebar.php          # Collapsible role-aware sidebar
│   ├── footer.php           # Layout footer
│   └── chat_drawer.php      # Team messaging drawer
├── modules/                 # Modular business domains
│   ├── auth/                # Sign-in & session termination
│   ├── dashboard/           # Financial KPI cards & trajectory chart
│   ├── sales/               # Sales records
│   ├── orders/              # Order entry & COD pipeline
│   ├── expenses/            # Business expenses & categories
│   ├── inventory/           # Stock levels, movements & alerts
│   ├── products/            # Catalog management & pricing
│   ├── invoices/            # Invoices & thermal receipts
│   ├── reports/             # Financial reports & CSV export
│   ├── audit/               # Activity audit logs & diff inspector
│   ├── users/               # Staff management & role assignment
│   └── chat/                # Real-time team communication handler
├── uploads/                 # Secure storage for receipt attachments
├── index.php                # Main entry redirect
└── README.md                # System documentation
```

---

## 📄 License
This project is open-source and available under the [MIT License](LICENSE).
