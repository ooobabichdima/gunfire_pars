-- Migration 003: Content generation fields

ALTER TABLE `catalog_products`
    ADD COLUMN `store_name` VARCHAR(500) DEFAULT NULL COMMENT 'Generated name for store' AFTER `normalized_name`,
    ADD COLUMN `store_description` TEXT DEFAULT NULL COMMENT 'Generated product card HTML' AFTER `store_name`,
    ADD COLUMN `meta_title` VARCHAR(255) DEFAULT NULL AFTER `store_description`,
    ADD COLUMN `meta_description` VARCHAR(500) DEFAULT NULL AFTER `meta_title`,
    ADD COLUMN `meta_keywords` VARCHAR(500) DEFAULT NULL AFTER `meta_description`,
    ADD COLUMN `content_generated_at` DATETIME DEFAULT NULL AFTER `meta_keywords`;

CREATE TABLE IF NOT EXISTS `content_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `prompt` TEXT NOT NULL,
    `type` ENUM('name', 'seo', 'card', 'custom') NOT NULL DEFAULT 'custom',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_template_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `content_templates` (`code`, `name`, `prompt`, `type`) VALUES
('store_name_ua', 'Назва для магазину (UA)', 'Створи коротку привабливу назву товару українською мовою для інтернет-магазину страйкболу.\n\nОригінальна назва: {name}\nБренд: {brand}\nМодель: {model}\nКатегорія: {category}\n\nВимоги:\n- Максимум 80 символів\n- Українською мовою\n- Включити бренд і модель\n- Без зайвих слів типу "купити", "ціна"\n- Формат: [Бренд] [Тип] [Модель] [Ключова характеристика]', 'name'),

('seo_meta', 'SEO мета-теги', 'Створи SEO мета-теги для товару в інтернет-магазині страйкболу.\n\nНазва: {name}\nБренд: {brand}\nМодель: {model}\nОпис: {description}\nХарактеристики: {specifications}\nЦіна: {price} {currency}\n\nВідповідь у форматі JSON:\n{{\n  "meta_title": "до 60 символів, українською",\n  "meta_description": "до 160 символів, українською, з ключовими словами",\n  "meta_keywords": "через кому, 5-10 ключових слів українською"\n}}', 'seo'),

('product_card', 'Картка товару', 'Створи повний опис товару для картки в інтернет-магазині страйкболу.\n\nОригінальна назва: {name}\nБренд: {brand}\nМодель: {model}\nОпис від постачальника: {description}\nХарактеристики: {specifications}\n\nСтруктура відповіді (HTML):\n1. Короткий привабливий опис (2-3 речення)\n2. Розділ "Особливості" — список 5-8 ключових переваг\n3. Розділ "Характеристики" — таблиця\n4. Розділ "Комплектація" — якщо є інформація\n\nВимоги:\n- Українською мовою\n- HTML формат з тегами <h3>, <ul>, <li>, <table>\n- Професійний стиль\n- Без вигаданих характеристик — тільки те що є в даних', 'card')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
