<?php

declare(strict_types=1);

namespace App\Suppliers;

interface SupplierParserInterface
{
    /**
     * Returns the supplier code (e.g. 'gunfire').
     */
    public function getSupplierCode(): string;

    /**
     * Scan all category URLs from the supplier's site.
     * @return array<string> List of category URLs.
     */
    public function scanCategories(): array;

    /**
     * Scan product listing pages within a category and return product URLs.
     * @return array<string> List of product URLs.
     */
    public function scanListings(string $categoryUrl, int $limit = 0): array;

    /**
     * Parse a single product page and return structured data.
     * @return array<string, mixed>|null Parsed product data or null on failure.
     */
    public function parseProduct(string $url): ?array;

    /**
     * Quick price/availability check without full parsing.
     * @return array{price_purchase: ?float, price_regular: ?float, currency: string, availability: ?string, is_active: bool}|null
     */
    public function checkPrice(string $url): ?array;
}
