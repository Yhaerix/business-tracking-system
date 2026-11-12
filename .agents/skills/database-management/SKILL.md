---
name: database-management
description: Procedures and standards for executing schema migrations, managing InnoDB constraints, seeding initial data, and maintaining database integrity.
---

# Database Management Skill

## 1. When to Use
Invoke this skill whenever designing new database tables, applying migrations, running seed scripts, adding indexes, or maintaining referential integrity in MySQL/MariaDB.

---

## 2. Core MySQL & Storage Engine Standards

### 2.1. Engine & Character Set
- **Engine**: Always enforce `ENGINE=InnoDB` for full ACID transaction support and foreign key constraints.
- **Charset & Collation**: Always specify `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`.

### 2.2. Data Types & Precision Rules
- **Monetary Values**: Always use `DECIMAL(12,2)`. **Never** use `FLOAT` or `DOUBLE` for financial amounts (subtotals, discounts, delivery fees, expenses).
- **Primary Keys**: Use `INT UNSIGNED AUTO_INCREMENT` for lookup/catalog tables and `BIGINT UNSIGNED AUTO_INCREMENT` for high-volume transaction tables (`orders`, `order_items`, `inventory_movements`, `audit_logs`).
- **Dates & Times**: Use `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` for audit fields (`created_at`, `updated_at`). Use `DATE` for business transaction dates (`expense_date`, `issued_date`).
- **Soft Deletion**: Include `deleted_at TIMESTAMP NULL DEFAULT NULL` on all core business entities (`orders`, `expenses`, `products`).

### 2.3. Foreign Key Constraints
- Use explicit foreign keys:
  ```sql
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) 
      REFERENCES `users` (`id`) 
      ON UPDATE CASCADE 
      ON DELETE RESTRICT
  ```
- **Strict Prohibition**: Never use `ON DELETE CASCADE` on financial tables (`orders`, `expenses`, `invoices`, `payments`). Use `ON DELETE RESTRICT` so historical records cannot be accidentally orphaned or purged.

---

## 3. Schema Migration Workflow

When introducing schema additions or adjustments:
1. **Verify Against `docs/DATABASE_SCHEMA.md`**:
   - Check the canonical documentation before creating or modifying table structures.
2. **Write Idempotent DDL**:
   - Use `CREATE TABLE IF NOT EXISTS` and check column existence before running `ALTER TABLE`.
3. **Execution Procedure**:
   - Save the canonical base script to `docs/schema.sql`.
   - Execute migrations via a controlled PHP script using PDO or command-line MySQL.
   - Verify that tables and constraints were created successfully via `SHOW TABLES;` and `SHOW CREATE TABLE <table_name>;`.

---

## 4. Seeding Guidelines

Every clean environment requires baseline seed data:
1. **Roles**:
   - `admin`: Business Owner / Full Access.
   - `manager`: Daily operations, inventory, and expense approvals.
   - `cashier`: Point of sale and COD dispatch/collection entry.
2. **Default Admin User**:
   - Create one superuser account with a securely hashed password using `PASSWORD_BCRYPT`.
3. **Default Expense Categories**:
   - Seed common small business expense categories: `Inventory / Stock Purchase`, `Utilities (Water/Electric/Internet)`, `Rent`, `Packaging Materials`, `Delivery & Courier Fees`, `Office Supplies`.
4. **Default Product Categories**:
   - Seed at least one default product category (`General Merchandise`).

---

## 5. Indexing & Query Optimization

To maintain fast response times ($< 500\text{ms}$) as data grows:
- **Foreign Keys**: Ensure every foreign key column is indexed.
- **Searchable Serials**: Add unique indexes to `sku`, `order_number`, `invoice_number`, and `receipt_number`.
- **Status & Date Filters**: Composite indexes on frequently filtered queries:
  ```sql
  INDEX `idx_orders_status_date` (`order_status`, `created_at`),
  INDEX `idx_expenses_date` (`expense_date`, `deleted_at`)
  ```

---

## 6. Defensive Safety Invariants
- **No Production Truncates**: Never run `TRUNCATE` or `DROP TABLE` on populated tables.
- **Safe Alterations**: When altering columns with existing data, always verify nullability and data conversion safety.
