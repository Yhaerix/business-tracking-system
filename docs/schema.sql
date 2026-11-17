-- ==============================================================================
-- Small Business Sales and Expense Tracking System
-- Canonical Database DDL & Baseline Seed Script
-- Engine: MySQL 8.x / MariaDB (InnoDB, utf8mb4)
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `business_tracking_db` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `business_tracking_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 0. Clean Slate Reset (Drop Existing Tables Before Recreating)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `chat_messages`;
DROP TABLE IF EXISTS `wallet_transactions`;
DROP TABLE IF EXISTS `customer_wallets`;
DROP TABLE IF EXISTS `store_settings`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `reminders`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `order_notes`;
DROP TABLE IF EXISTS `expenses`;
DROP TABLE IF EXISTS `expense_categories`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `cod_payments`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `customer_addresses`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `inventory_movements`;
DROP TABLE IF EXISTS `product_images`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `auth_otps`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;

-- ------------------------------------------------------------------------------
-- 1. Authentication & RBAC
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `label` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `full_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `failed_logins` INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` TIMESTAMP NULL DEFAULT NULL,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_email` (`email`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auth_otps` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_type` ENUM('admin', 'customer') NOT NULL DEFAULT 'admin',
    `user_id` BIGINT UNSIGNED NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `otp_code_hash` VARCHAR(255) NOT NULL,
    `otp_code_plain` VARCHAR(10) NULL,
    `purpose` ENUM('login', 'register', 'password_reset', 'wallet_transfer') NOT NULL DEFAULT 'login',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3,
    `expires_at` TIMESTAMP NOT NULL,
    `is_used` TINYINT(1) NOT NULL DEFAULT 0,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_active_otp` (`user_type`, `user_id`, `purpose`, `is_used`, `expires_at`),
    INDEX `idx_otp_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. Product Catalog & Inventory
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED NOT NULL,
    `sku` VARCHAR(64) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `specs` TEXT NULL,
    `color` VARCHAR(50) NULL,
    `storage` VARCHAR(50) NULL,
    `condition_type` VARCHAR(50) NOT NULL DEFAULT 'Brand New Sealed',
    `warranty` VARCHAR(100) NOT NULL DEFAULT '1 Year Official Apple Warranty',
    `cost_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `selling_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `current_stock` INT NOT NULL DEFAULT 0,
    `low_stock_threshold` INT NOT NULL DEFAULT 5,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX `idx_products_sku` (`sku`),
    INDEX `idx_products_status` (`status`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_images` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `image_path` VARCHAR(255) NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `display_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX `idx_product_images_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_movements` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `movement_type` ENUM('INITIAL','RESTOCK','SALE','RETURN','DAMAGE','ADJUSTMENT') NOT NULL,
    `quantity` INT NOT NULL,
    `stock_before` INT NOT NULL,
    `stock_after` INT NOT NULL,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` BIGINT UNSIGNED NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_movements_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_movements_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX `idx_movements_product` (`product_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. Customers & Addresses
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `customers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NULL UNIQUE,
    `phone` VARCHAR(30) NOT NULL,
    `password_hash` VARCHAR(255) NULL,
    `wallet_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `notes` TEXT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_customers_phone` (`phone`),
    INDEX `idx_customers_email` (`email`),
    INDEX `idx_customers_status` (`status`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_addresses` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `address_line1` VARCHAR(255) NOT NULL,
    `address_line2` VARCHAR(255) NULL,
    `city` VARCHAR(100) NOT NULL,
    `province_state` VARCHAR(100) NOT NULL,
    `postal_code` VARCHAR(20) NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_customer_addresses_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX `idx_customer_addresses_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. Orders, Line Items & COD Payments
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `orders` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(32) NOT NULL UNIQUE,
    `user_id` INT UNSIGNED NOT NULL,
    `customer_id` BIGINT UNSIGNED NULL,
    `customer_name` VARCHAR(100) NOT NULL,
    `customer_phone` VARCHAR(30) NOT NULL,
    `delivery_address` TEXT NOT NULL,
    `delivery_notes` TEXT NULL,
    `courier_name` VARCHAR(100) NULL,
    `tracking_number` VARCHAR(100) NULL,
    `shipping_status` ENUM('NOT_SHIPPED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED', 'FAILED_DELIVERY', 'RETURNED_TO_SENDER') NOT NULL DEFAULT 'NOT_SHIPPED',
    `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `delivery_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `order_status` ENUM('PENDING','CONFIRMED','DISPATCHED','DELIVERED','CANCELLED','RETURNED') DEFAULT 'PENDING',
    `payment_method` VARCHAR(20) NOT NULL DEFAULT 'COD',
    `payment_status` ENUM('PENDING_COLLECTION','COLLECTED','REMITTED','FAILED') DEFAULT 'PENDING_COLLECTION',
    `dispatched_at` TIMESTAMP NULL DEFAULT NULL,
    `delivered_at` TIMESTAMP NULL DEFAULT NULL,
    `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
    `cancellation_reason` VARCHAR(255) NULL,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX `idx_orders_customer` (`customer_id`),
    INDEX `idx_orders_shipping_status` (`shipping_status`),
    INDEX `idx_orders_status_date` (`order_status`, `created_at`),
    INDEX `idx_orders_payment_status` (`payment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `product_name` VARCHAR(150) NOT NULL,
    `unit_cost` DECIMAL(12,2) NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `quantity` INT NOT NULL,
    `line_total` DECIMAL(12,2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cod_payments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` BIGINT UNSIGNED NOT NULL UNIQUE,
    `amount_due` DECIMAL(12,2) NOT NULL,
    `amount_collected` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `collected_by` VARCHAR(100) NULL,
    `collected_at` TIMESTAMP NULL DEFAULT NULL,
    `remitted_to_user_id` INT UNSIGNED NULL,
    `remitted_at` TIMESTAMP NULL DEFAULT NULL,
    `status` ENUM('PENDING','COLLECTED','REMITTED','FAILED') DEFAULT 'PENDING',
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_cod_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_cod_remitted_user` FOREIGN KEY (`remitted_to_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` BIGINT UNSIGNED NOT NULL UNIQUE,
    `invoice_number` VARCHAR(32) NOT NULL UNIQUE,
    `receipt_number` VARCHAR(32) NOT NULL UNIQUE,
    `issued_date` DATE NOT NULL,
    `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('ISSUED','PAID','VOID') DEFAULT 'ISSUED',
    `void_reason` VARCHAR(255) NULL,
    `pdf_path` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_invoices_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. Expenses Management
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `expense_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `expense_date` DATE NOT NULL,
    `receipt_path` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `deletion_reason` VARCHAR(255) NULL,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_expenses_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_expenses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX `idx_expenses_date` (`expense_date`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. Communication & Notifications
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `order_notes` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `note` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notes_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `type` ENUM('LOW_STOCK','COD_PENDING','ORDER_DISPATCHED','SYSTEM') NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reminders` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `type` ENUM('INCOMING_DELIVERY', 'ORDER_DISPATCH', 'LOW_STOCK_CHECK', 'COD_FOLLOWUP', 'EXPENSE_DUE', 'CUSTOM') NOT NULL,
    `target_date` DATETIME NOT NULL,
    `notes` TEXT NULL,
    `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_run_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_reminders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX `idx_reminders_user_target` (`user_id`, `target_date`, `is_completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. Audit Logging
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `module` VARCHAR(50) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `record_id` BIGINT UNSIGNED NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX `idx_audit_module_action` (`module`, `action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. Store Settings
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `store_settings` (
    `setting_key` VARCHAR(64) PRIMARY KEY,
    `setting_value` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8.1. Prestige Coins Wallet & Transaction Ledger
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `customer_wallets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` BIGINT UNSIGNED NOT NULL UNIQUE,
    `coin_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `is_frozen` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_customer_wallets_customer` FOREIGN KEY (`customer_id`) 
        REFERENCES `customers` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX `idx_wallets_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `wallet_id` BIGINT UNSIGNED NOT NULL,
    `transaction_type` ENUM('TOP_UP', 'ORDER_PAYMENT', 'REFUND', 'MANUAL_ADJUSTMENT') NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `balance_before` DECIMAL(12,2) NOT NULL,
    `balance_after` DECIMAL(12,2) NOT NULL,
    `reference_order_id` BIGINT UNSIGNED NULL,
    `payment_gateway` VARCHAR(50) NULL COMMENT 'GCASH, MAYA, BANK_TRANSFER, ADMIN',
    `reference_code` VARCHAR(100) NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_wallet_transactions_wallet` FOREIGN KEY (`wallet_id`) 
        REFERENCES `customer_wallets` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_wallet_transactions_order` FOREIGN KEY (`reference_order_id`) 
        REFERENCES `orders` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX `idx_wallet_trans_wallet` (`wallet_id`, `created_at`),
    INDEX `idx_wallet_trans_order` (`reference_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. Internal Team Communication (Chat & Messages)
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sender_id` INT UNSIGNED NOT NULL,
    `recipient_id` INT UNSIGNED NULL COMMENT 'NULL indicates public store-wide #team channel',
    `message` TEXT NOT NULL,
    `order_id` BIGINT UNSIGNED NULL COMMENT 'Optional order attachment for quick review',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_chat_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_chat_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_chat_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX `idx_chat_participants` (`sender_id`, `recipient_id`, `created_at`),
    INDEX `idx_chat_channel` (`recipient_id`, `created_at`),
    INDEX `idx_chat_unread` (`recipient_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- Baseline Seed Data
-- ==============================================================================

-- 1. Roles
INSERT INTO `roles` (`id`, `name`, `label`, `description`) VALUES
(1, 'admin', 'Business Owner', 'Full system ownership, financial authority, and settings control'),
(2, 'manager', 'Store Manager', 'Daily operations, stock adjustments, and expense approvals'),
(3, 'cashier', 'Cashier / Sales Clerk', 'Point of sale, COD order creation, and delivery updates')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `description` = VALUES(`description`);

-- 2. Default Owner User: admin / admin123
INSERT INTO `users` (`id`, `role_id`, `username`, `full_name`, `email`, `password_hash`, `is_active`) VALUES
(1, 1, 'admin', 'Business Owner', 'admin@prestigeapple.ph', '$2y$12$4z8XgJp/EiHOxgr.IWc1N.Wc01sRVTSB5tkepGsntq8yOa3.dmFaS', 1)
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`), `email` = VALUES(`email`), `password_hash` = VALUES(`password_hash`);

-- 3. Apple Store Product Categories
INSERT INTO `categories` (`id`, `name`, `description`) VALUES
(1, 'General Merchandise', 'Default catalog category'),
(2, 'iPhones', 'Flagship Apple iPhones with Super Retina XDR and Titanium designs'),
(3, 'iPads', 'Apple iPad Pro, iPad Air, iPad mini, and standard iPad with Apple Silicon'),
(4, 'MacBooks', 'Apple MacBook Air and MacBook Pro powered by M-series Silicon'),
(5, 'Apple Watches', 'Apple Watch Ultra, Series 10, and SE with advanced fitness & health tracking'),
(6, 'AirPods & Audio', 'AirPods Pro, AirPods 4 ANC, and AirPods Max with Spatial Audio'),
(7, 'Apple Accessories', 'Authentic MagSafe chargers, 20W adapters, braided cables, and leather cases')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- 4. Official Apple Product Catalog (10 Products)
INSERT INTO `products` (`id`, `category_id`, `sku`, `name`, `description`, `color`, `storage`, `condition_type`, `warranty`, `cost_price`, `selling_price`, `current_stock`, `low_stock_threshold`, `status`) VALUES
(10, 2, 'APL-IP16PM-256-DT', 'iPhone 16 Pro Max 256GB', 'The ultimate iPhone featuring a strong and lightweight grade 5 titanium design with a larger 6.9-inch Super Retina XDR display.', 'Desert Titanium', '256GB', 'Open Box', '1 Year Official Apple Warranty', 74500.00, 84990.00, 25, 5, 'active'),
(11, 2, 'APL-IP16P-128-NT', 'iPhone 16 Pro 128GB', 'Remarkable titanium design, Camera Control, 4K 120 fps Dolby Vision, and the industry-leading A18 Pro chip.', 'Natural Titanium', '128GB', 'Brand New Sealed', '1 Year Official Apple Warranty', 61200.00, 69990.00, 30, 5, 'active'),
(12, 2, 'APL-IP16-128-TL', 'iPhone 16 128GB', 'Equipped with Camera Control, a 48MP Fusion camera, Action button, and the powerhouse A18 chip.', 'Teal', '128GB', 'Brand New Sealed', '1 Year Official Apple Warranty', 48000.00, 54990.00, 40, 5, 'active'),
(13, 4, 'APL-MBA13-M3-16-256-MD', 'MacBook Air 13-inch M3 16GB/256GB', 'Super-portable laptop powered by the Apple M3 chip. Built for Apple Intelligence with up to 18 hours of battery life.', 'Midnight', '16GB / 256GB SSD', 'Brand New Sealed', '1 Year Official Apple Warranty', 60000.00, 69990.00, 20, 3, 'active'),
(14, 4, 'APL-MBP14-M3P-18-512-SB', 'MacBook Pro 14-inch M3 Pro 18GB/512GB', 'Pro-grade workstation with Apple M3 Pro, Liquid Retina XDR display, and extensive pro connectivity.', 'Space Black', '18GB / 512GB SSD', 'Brand New Sealed', '1 Year Official Apple Warranty', 112000.00, 129990.00, 15, 2, 'active'),
(15, 3, 'APL-IPP11-M4-256-SB', 'iPad Pro 11-inch M4 Ultra Retina OLED 256GB', 'The impossibly thin iPad Pro featuring the breakthrough Ultra Retina XDR tandem OLED display and the next-generation M4 chip.', 'Space Black', '256GB', 'Brand New Sealed', '1 Year Official Apple Warranty', 63500.00, 72990.00, 25, 3, 'active'),
(16, 5, 'APL-AWU2-49-OR', 'Apple Watch Ultra 2 GPS + Cellular 49mm', 'The ultimate sports and adventure watch. Robust titanium case, dual-frequency GPS, and up to 72 hours of battery in Low Power Mode.', 'Natural Titanium / Orange', '64GB', 'Brand New Sealed', '1 Year Official Apple Warranty', 47000.00, 54990.00, 15, 2, 'active'),
(17, 5, 'APL-AWS10-46-JB', 'Apple Watch Series 10 GPS 46mm', 'Our thinnest watch with our biggest display ever. Advanced health sensors with sleep apnea notifications and fast charging.', 'Jet Black', '64GB', 'Brand New Sealed', '1 Year Official Apple Warranty', 22500.00, 26490.00, 30, 5, 'active'),
(18, 6, 'APL-APP2-USBC', 'AirPods Pro 2 (USB-C) with MagSafe Case', 'Pro-level Active Noise Cancellation, Adaptive Audio, Transparency mode, and Personalized Spatial Audio with dynamic head tracking.', 'White', 'N/A', 'Brand New Sealed', '1 Year Official Apple Warranty', 11800.00, 14990.00, 50, 10, 'active'),
(19, 7, 'APL-ACC-20W-ADP', 'Apple 20W USB-C Power Adapter', 'Official Apple 20W Power Adapter provides fast, efficient charging at home, in the office, or on the go.', 'White', 'N/A', 'Brand New Sealed', '1 Year Official Apple Warranty', 750.00, 1190.00, 100, 20, 'active')
ON DUPLICATE KEY UPDATE 
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `color` = VALUES(`color`),
    `storage` = VALUES(`storage`),
    `condition_type` = VALUES(`condition_type`),
    `warranty` = VALUES(`warranty`),
    `cost_price` = VALUES(`cost_price`),
    `selling_price` = VALUES(`selling_price`),
    `current_stock` = VALUES(`current_stock`),
    `low_stock_threshold` = VALUES(`low_stock_threshold`),
    `status` = VALUES(`status`),
    `deleted_at` = NULL;

-- 4b. Initial Inventory Movements for the 10 Products
INSERT INTO `inventory_movements` (`product_id`, `user_id`, `movement_type`, `quantity`, `stock_before`, `stock_after`, `reference_type`, `notes`) VALUES
(10, 1, 'INITIAL', 25, 0, 25, 'SYSTEM_SEED', 'Initial catalog stock seeded'),
(11, 1, 'INITIAL', 30, 0, 30, 'SYSTEM_SEED', 'Initial catalog stock seeded'),
(12, 1, 'INITIAL', 40, 0, 40, 'SYSTEM_SEED', 'Initial catalog stock seeded'),
(13, 1, 'INITIAL', 20, 0, 20, 'SYSTEM_SEED', 'Initial catalog stock seeded'),
(14, 1, 'INITIAL', 15, 0, 15, 'SYSTEM_SEED', 'Initial catalog stock seeded'),
(15, 1, 'INITIAL', 25, 0, 25, 'SYSTEM_SEED', 'Initial catalog stock seeded'),
(16, 1, 'INITIAL', 15, 0, 15, 'SYSTEM_SEED', 'Initial catalog stock seeded'),
(17, 1, 'INITIAL', 30, 0, 30, 'SYSTEM_SEED', 'Initial catalog stock seeded'),
(18, 1, 'INITIAL', 50, 0, 50, 'SYSTEM_SEED', 'Initial catalog stock seeded'),
(19, 1, 'INITIAL', 100, 0, 100, 'SYSTEM_SEED', 'Initial catalog stock seeded');

-- 5. Baseline Customer Account (#1 VIP Shopper)
INSERT INTO `customers` (`id`, `name`, `email`, `phone`, `password_hash`, `wallet_balance`, `email_verified_at`, `status`) VALUES
(1, 'Prestige VIP Shopper', 'vip@prestigeapple.ph', '+63 917 800 2775', '$2y$12$NSmpFphyjz4LiFXkwANcse3jgJKjfNrlse0dgWJQUT0hRmTag1dR.', 0.00, NOW(), 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `password_hash` = VALUES(`password_hash`), `wallet_balance` = VALUES(`wallet_balance`);

-- 6. Baseline Customer Wallet (#1)
INSERT INTO `customer_wallets` (`id`, `customer_id`, `coin_balance`, `is_frozen`) VALUES
(1, 1, 0.00, 0)
ON DUPLICATE KEY UPDATE `coin_balance` = VALUES(`coin_balance`);

-- 7. Default Expense Categories
INSERT INTO `expense_categories` (`id`, `name`, `description`) VALUES
(1, 'Inventory & Stock Purchase', 'Purchases of raw goods or resale stock'),
(2, 'Utilities (Electric/Water/Internet)', 'Monthly utility operational bills'),
(3, 'Store Rent', 'Physical store or warehouse lease'),
(4, 'Packaging & Supplies', 'Boxes, tape, bubble wrap, labels'),
(5, 'Delivery & Courier Fees', 'Third-party courier charges and rider fuel'),
(6, 'Office & Maintenance', 'Cleaning supplies, receipt paper rolls, repairs')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 8. Default Store Settings
INSERT INTO `store_settings` (`setting_key`, `setting_value`) VALUES
('store_name', 'Prestige Apple Store'),
('contact_email', 'support@prestigeapple.ph'),
('contact_phone', '+63 917 800 2775'),
('currency_symbol', '₱'),
('default_low_stock_alert', '5'),
('coins_name', 'Prestige Coins'),
('coins_symbol', '🪙'),
('coins_conversion_rate', '1.00'),
('coins_enabled', '1')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- 9. Default Operational & Incoming Delivery Reminders
INSERT INTO `reminders` (`user_id`, `title`, `type`, `target_date`, `notes`, `is_completed`, `is_active`) VALUES
(1, 'Incoming Delivery: iPhone 16 Pro Max 256GB (15 units) — Supplier Shipment', 'INCOMING_DELIVERY', DATE_ADD(CURDATE(), INTERVAL 34 HOUR), 'Verify factory seals, IMEI serial numbers, and log restock in Stock Ledger upon arrival.', 0, 1),
(1, 'Incoming Delivery: AirPods Pro 2 (USB-C) & 20W USB-C Power Adapters (30 units)', 'INCOMING_DELIVERY', DATE_ADD(CURDATE(), INTERVAL 62 HOUR), 'Receive from Apple Authorized Distributor courier & inspect outer box condition.', 0, 1),
(1, 'Customer Deliveries: Follow up In-Transit & Ready-to-Ship COD Orders', 'ORDER_DISPATCH', DATE_ADD(CURDATE(), INTERVAL 16 HOUR), 'Check courier waybill milestones and confirm scheduled customer deliveries for today.', 0, 1);
