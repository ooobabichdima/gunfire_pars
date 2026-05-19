-- Migration 001: CMS tables
-- Run after schema.sql

-- -------------------------------------------
-- Supplier Schedules
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `supplier_schedules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` INT UNSIGNED NOT NULL,
    `job_type` ENUM('scan', 'parse', 'match', 'relations', 'prices') NOT NULL,
    `cron_expression` VARCHAR(100) NOT NULL DEFAULT '0 */6 * * *',
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `batch_size` INT UNSIGNED NOT NULL DEFAULT 50,
    `last_run_at` DATETIME DEFAULT NULL,
    `next_run_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_schedule_supplier_job` (`supplier_id`, `job_type`),
    CONSTRAINT `fk_schedule_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Job Runs (execution history)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `job_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` INT UNSIGNED DEFAULT NULL,
    `job_type` VARCHAR(50) NOT NULL,
    `status` ENUM('running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'running',
    `pid` INT UNSIGNED DEFAULT NULL,
    `params_json` JSON DEFAULT NULL,
    `result_json` JSON DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_jobruns_supplier` (`supplier_id`),
    KEY `idx_jobruns_status` (`status`),
    KEY `idx_jobruns_started` (`started_at`),
    CONSTRAINT `fk_jobruns_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Settings (key-value)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Add IBIS supplier
-- -------------------------------------------
INSERT INTO `suppliers` (`code`, `name`, `type`, `base_url`, `is_active`, `config_json`)
VALUES ('ibis', 'IBIS.net.ua', 'b2b', 'https://ibis.net.ua', 1, JSON_OBJECT(
    'currency', 'UAH',
    'locale', 'ua',
    'xls_base_url', 'https://obmen.ibis.net.ua/arm/',
    'xls_auth_login', 'arm',
    'xls_auth_password', 'arm',
    'photo_base_url', 'https://ibis.net.ua',
    'xls_files', JSON_ARRAY(
        'Вільні залишки Київ.xls',
        'Вільні залишки Борислав.xls',
        'Вільні залишки збройові аксесуари.xls'
    ),
    'exchange_rate_file', 'Курси валют на 19.05.2026.xlsx'
))
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
