# Supplier Aggregator — Multi-Supplier Product Parser & Price Comparison

PHP CLI система агрегации товаров и сравнения цен от нескольких поставщиков.

## Архитектура

Система построена на 3-х уровнях:

1. **catalog_products** — нормализованный каталог товаров
2. **suppliers** — источники (gunfire, b2b, и т.д.)
3. **supplier_offers** — предложения поставщиков (цены, наличие, условия)

Один `catalog_product` может иметь множество `supplier_offers` от разных поставщиков.

## Требования

- PHP 8.1+
- MySQL 5.7+ / 8.0
- Composer
- Расширения: pdo_mysql, json, mbstring

## Установка

```bash
# Клонировать репозиторий
git clone <repo-url> && cd gunfire_pars

# Установить зависимости
composer install

# Создать базу данных
mysql -u root -p -e "CREATE DATABASE supplier_aggregator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Импортировать схему
mysql -u root -p supplier_aggregator < sql/schema.sql
```

## Конфигурация

Настройки в `config/config.php`. Переменные окружения:

| Переменная   | Описание           | По умолчанию       |
|-------------|-------------------|---------------------|
| `DB_HOST`   | MySQL host        | `127.0.0.1`         |
| `DB_PORT`   | MySQL port        | `3306`               |
| `DB_NAME`   | Имя базы          | `supplier_aggregator`|
| `DB_USER`   | Пользователь      | `root`               |
| `DB_PASS`   | Пароль            | (пусто)              |
| `LOG_LEVEL` | Уровень логов     | `info`               |

## Использование

### 1. Сканирование категорий и товаров

```bash
# Сканировать все категории и собрать URL товаров в очередь
php scan_suppliers.php --supplier=gunfire

# Только категории
php scan_suppliers.php --supplier=gunfire --mode=categories

# С лимитом товаров на категорию
php scan_suppliers.php --supplier=gunfire --limit=100
```

### 2. Парсинг товаров из очереди

```bash
# Распарсить 50 товаров из очереди
php parse_offers.php --supplier=gunfire --limit=50

# Большой батч
php parse_offers.php --supplier=gunfire --limit=500
```

### 3. Сопоставление товаров (matching)

```bash
# Привязать supplier_offers к catalog_products
php match_products.php --limit=500

# Только для конкретного поставщика
php match_products.php --supplier=gunfire --limit=1000
```

### 4. Построение категорий и связей

```bash
php build_relations.php --limit=500
```

### 5. Обновление цен

```bash
# Обновить цены (легковесная проверка)
php update_prices.php --supplier=gunfire --limit=200

# Обновить конкретный товар
php update_prices.php --supplier=gunfire --product-id=123

# Сравнить цены между поставщиками
php update_prices.php --mode=compare --limit=50

# Сравнить для одного товара
php update_prices.php --mode=compare --product-id=123
```

## CLI аргументы (общие)

| Аргумент        | Описание                           |
|----------------|------------------------------------|
| `--supplier`   | Код поставщика (gunfire, b2b...)   |
| `--limit`      | Максимум записей для обработки     |
| `--offset`     | Смещение для пагинации             |
| `--product-id` | ID каталожного товара              |
| `--active`     | Фильтр по активности              |
| `--mode`       | Режим работы (refresh/compare)     |
| `--help`       | Справка                           |

## Cron (рекомендуемый schedule)

```cron
# Сканировать новые товары — раз в день в 2:00
0 2 * * * cd /path/to/gunfire_pars && php scan_suppliers.php --supplier=gunfire >> /dev/null 2>&1

# Парсить очередь — каждые 15 минут
*/15 * * * * cd /path/to/gunfire_pars && php parse_offers.php --supplier=gunfire --limit=100 >> /dev/null 2>&1

# Matching — каждый час
0 * * * * cd /path/to/gunfire_pars && php match_products.php --limit=500 >> /dev/null 2>&1

# Построение категорий — раз в день в 4:00
0 4 * * * cd /path/to/gunfire_pars && php build_relations.php --limit=1000 >> /dev/null 2>&1

# Обновление цен — каждые 30 минут
*/30 * * * * cd /path/to/gunfire_pars && php update_prices.php --supplier=gunfire --limit=200 >> /dev/null 2>&1
```

## Структура проекта

```
├── config/
│   └── config.php                    # Конфигурация
├── src/
│   ├── Database.php                  # PDO wrapper + upsert
│   ├── Logger.php                    # Файловый логгер
│   ├── HttpClient.php                # Guzzle + retry + throttle
│   ├── Lock.php                      # flock для предотвращения двойного запуска
│   ├── Suppliers/
│   │   ├── SupplierParserInterface.php   # Интерфейс парсера
│   │   ├── AbstractSupplierParser.php    # Базовый класс
│   │   └── Gunfire/
│   │       └── GunfireParser.php         # Парсер gunfire.com
│   ├── Catalog/
│   │   ├── ProductNormalizer.php     # Нормализация названий/брендов
│   │   └── ProductMatcher.php        # EAN → SKU → brand+model → fuzzy match
│   ├── Queue/
│   │   └── QueueManager.php          # Очередь задач с retry
│   └── Services/
│       ├── OfferUpdater.php          # Обновление цен + история
│       ├── RelationBuilder.php       # Построение категорий из breadcrumbs
│       └── PriceComparator.php       # Сравнение цен между поставщиками
├── sql/
│   └── schema.sql                    # Схема БД
├── logs/                             # Логи (gitignored)
├── scan_suppliers.php                # CLI: сканирование категорий/листингов
├── parse_offers.php                  # CLI: парсинг товаров из очереди
├── match_products.php                # CLI: matching offers → catalog
├── build_relations.php               # CLI: построение категорий
├── update_prices.php                 # CLI: обновление цен + сравнение
├── composer.json
└── README.md
```

## Добавление нового поставщика

1. Создать `src/Suppliers/NewSupplier/NewSupplierParser.php`, наследуя `AbstractSupplierParser`
2. Реализовать методы `SupplierParserInterface`
3. Добавить запись в таблицу `suppliers`
4. Добавить конфиг в `config/config.php` → `suppliers`
5. Добавить `match` case в CLI скриптах

## Надёжность

- **Retry HTTP** — автоматический retry с экспоненциальным backoff
- **Throttling** — рандомная задержка 1.5–4 сек между запросами
- **Lock files** — flock предотвращает двойной запуск
- **Queue с retry** — ошибки повторяются до 3 раз
- **Prepared statements** — защита от SQL injection
- **Upsert** — безопасное обновление без дублей
- **Price history** — все изменения цен фиксируются
- **Fallback парсинг** — несколько CSS селекторов для каждого элемента

## Логи

Логи пишутся в `logs/` с ротацией по дням:
- `logs/scan_YYYY-MM-DD.log`
- `logs/parse_YYYY-MM-DD.log`
- `logs/match_YYYY-MM-DD.log`
- `logs/prices_YYYY-MM-DD.log`
