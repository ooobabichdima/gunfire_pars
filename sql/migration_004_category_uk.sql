-- Migration 004: Ukrainian name for categories
ALTER TABLE `categories`
    ADD COLUMN `name_uk` VARCHAR(255) DEFAULT NULL COMMENT 'Ukrainian name' AFTER `name`;
