-- Migration 002: Price alerts + analytics
-- Run after migration_001_cms.sql

CREATE TABLE IF NOT EXISTS `price_alerts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `catalog_product_id` INT UNSIGNED DEFAULT NULL,
    `supplier_id` INT UNSIGNED DEFAULT NULL,
    `alert_type` ENUM('price_drop', 'price_increase', 'out_of_stock', 'back_in_stock', 'new_offer') NOT NULL,
    `threshold_percent` DECIMAL(5,2) DEFAULT NULL COMMENT 'Trigger when price changes by this %',
    `old_value` VARCHAR(255) DEFAULT NULL,
    `new_value` VARCHAR(255) DEFAULT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_alerts_unread` (`is_read`, `created_at`),
    KEY `idx_alerts_product` (`catalog_product_id`),
    KEY `idx_alerts_supplier` (`supplier_id`),
    CONSTRAINT `fk_alerts_product` FOREIGN KEY (`catalog_product_id`) REFERENCES `catalog_products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_alerts_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
