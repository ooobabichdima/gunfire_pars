-- Supplier Aggregator Database Schema
-- MySQL 5.7+ / 8.0

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- -------------------------------------------
-- 1. Suppliers
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `suppliers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL COMMENT 'Unique code: gunfire, b2b, taiwangun, etc.',
    `name` VARCHAR(255) NOT NULL,
    `type` ENUM('site', 'api', 'csv', 'b2b') NOT NULL DEFAULT 'site',
    `base_url` VARCHAR(500) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `config_json` JSON DEFAULT NULL COMMENT 'Supplier-specific config (rate limits, auth, etc.)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_suppliers_code` (`code`),
    KEY `idx_suppliers_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 2. Categories
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` INT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL,
    `level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_slug` (`slug`),
    KEY `idx_categories_parent` (`parent_id`),
    CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 3. Catalog Products (normalized)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `catalog_products` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `brand` VARCHAR(255) DEFAULT NULL,
    `name` VARCHAR(500) NOT NULL,
    `model` VARCHAR(255) DEFAULT NULL,
    `sku` VARCHAR(255) DEFAULT NULL,
    `ean` VARCHAR(20) DEFAULT NULL,
    `normalized_name` VARCHAR(500) NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `attributes_json` JSON DEFAULT NULL,
    `is_bundle` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_catalog_brand` (`brand`),
    KEY `idx_catalog_sku` (`sku`),
    KEY `idx_catalog_ean` (`ean`),
    KEY `idx_catalog_model` (`model`),
    KEY `idx_catalog_normalized` (`normalized_name`(191)),
    KEY `idx_catalog_category` (`category_id`),
    CONSTRAINT `fk_catalog_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 4. Product Categories (many-to-many)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `product_categories` (
    `product_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`product_id`, `category_id`),
    KEY `idx_pc_category` (`category_id`),
    CONSTRAINT `fk_pc_product` FOREIGN KEY (`product_id`) REFERENCES `catalog_products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pc_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 5. Supplier Offers
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `supplier_offers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` INT UNSIGNED NOT NULL,
    `catalog_product_id` INT UNSIGNED DEFAULT NULL COMMENT 'Linked after matching',
    `external_id` VARCHAR(255) DEFAULT NULL COMMENT 'Product ID from supplier',
    `external_sku` VARCHAR(255) DEFAULT NULL,
    `url` VARCHAR(1000) DEFAULT NULL,
    `name` VARCHAR(500) NOT NULL,
    `brand` VARCHAR(255) DEFAULT NULL,
    `model` VARCHAR(255) DEFAULT NULL,
    `ean` VARCHAR(20) DEFAULT NULL,
    `price_purchase` DECIMAL(12,2) DEFAULT NULL,
    `price_regular` DECIMAL(12,2) DEFAULT NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'PLN',
    `availability` VARCHAR(100) DEFAULT NULL,
    `stock_qty_text` VARCHAR(255) DEFAULT NULL,
    `delivery_cost` DECIMAL(10,2) DEFAULT NULL,
    `min_order_qty` INT UNSIGNED DEFAULT 1,
    `order_multiple` INT UNSIGNED DEFAULT 1,
    `lead_time_days` TINYINT UNSIGNED DEFAULT NULL,
    `is_bundle` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `raw_data_json` JSON DEFAULT NULL,
    `last_seen_at` DATETIME DEFAULT NULL,
    `last_price_check_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_supplier_offer` (`supplier_id`, `external_id`),
    KEY `idx_offers_catalog` (`catalog_product_id`),
    KEY `idx_offers_brand` (`brand`),
    KEY `idx_offers_sku` (`external_sku`),
    KEY `idx_offers_ean` (`ean`),
    KEY `idx_offers_active` (`is_active`),
    KEY `idx_offers_last_seen` (`last_seen_at`),
    CONSTRAINT `fk_offers_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_offers_catalog` FOREIGN KEY (`catalog_product_id`) REFERENCES `catalog_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 6. Supplier Offer Price History
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `supplier_offer_price_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_offer_id` INT UNSIGNED NOT NULL,
    `price_purchase` DECIMAL(12,2) DEFAULT NULL,
    `price_regular` DECIMAL(12,2) DEFAULT NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'PLN',
    `availability` VARCHAR(100) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_history_offer` (`supplier_offer_id`),
    KEY `idx_history_checked` (`checked_at`),
    CONSTRAINT `fk_history_offer` FOREIGN KEY (`supplier_offer_id`) REFERENCES `supplier_offers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 7. Parse Queue
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `parse_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` INT UNSIGNED NOT NULL,
    `url` VARCHAR(1000) NOT NULL,
    `type` ENUM('category', 'listing', 'product') NOT NULL DEFAULT 'product',
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1=highest, 10=lowest',
    `status` ENUM('new', 'processing', 'done', 'error', 'skipped') NOT NULL DEFAULT 'new',
    `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_retries` TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `error_message` TEXT DEFAULT NULL,
    `payload_json` JSON DEFAULT NULL COMMENT 'Extra data for processing',
    `scheduled_at` DATETIME DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_queue_status` (`status`, `priority`, `scheduled_at`),
    KEY `idx_queue_supplier` (`supplier_id`),
    KEY `idx_queue_url` (`url`(191)),
    CONSTRAINT `fk_queue_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Initial data: Gunfire supplier
-- -------------------------------------------
INSERT INTO `suppliers` (`code`, `name`, `type`, `base_url`, `is_active`)
VALUES ('gunfire', 'Gunfire.com', 'site', 'https://gunfire.com', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
