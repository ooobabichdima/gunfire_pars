<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Logger;

final class RelationBuilder
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Build category relations from raw breadcrumb data in supplier_offers.
     */
    public function buildCategoryRelations(int $limit = 500, int $offset = 0): array
    {
        $stats = ['processed' => 0, 'categories_created' => 0, 'relations_created' => 0];

        $offers = $this->db->fetchAll(
            "SELECT so.id, so.catalog_product_id, so.raw_data_json
             FROM supplier_offers so
             WHERE so.catalog_product_id IS NOT NULL
               AND so.raw_data_json IS NOT NULL
               AND so.is_active = 1
             ORDER BY so.id ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        foreach ($offers as $offer) {
            $rawData = json_decode($offer['raw_data_json'] ?? '{}', true);
            $breadcrumbs = $rawData['breadcrumbs'] ?? [];

            if (empty($breadcrumbs) || $offer['catalog_product_id'] === null) {
                continue;
            }

            $parentId = null;
            $lastCategoryId = null;

            foreach ($breadcrumbs as $index => $crumb) {
                $name = trim($crumb['name'] ?? '');
                if (empty($name) || mb_strtolower($name) === 'home' || mb_strtolower($name) === 'główna') {
                    continue;
                }

                $slug = $this->slugify($name);
                $categoryId = $this->findOrCreateCategory($name, $slug, $parentId, $index);

                if ($categoryId !== null) {
                    $lastCategoryId = $categoryId;
                    $parentId = $categoryId;
                    $stats['categories_created']++;
                }
            }

            // Link product to the deepest category
            if ($lastCategoryId !== null) {
                $this->linkProductCategory((int)$offer['catalog_product_id'], $lastCategoryId);
                $stats['relations_created']++;
            }

            $stats['processed']++;
        }

        $this->logger->info("Relation building complete", $stats);
        return $stats;
    }

    private function findOrCreateCategory(string $name, string $slug, ?int $parentId, int $level): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM categories WHERE slug = ?',
            [$slug]
        );

        if ($row) {
            return (int)$row['id'];
        }

        return $this->db->insert('categories', [
            'parent_id'  => $parentId,
            'name'       => $name,
            'slug'       => $slug,
            'level'      => $level,
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function linkProductCategory(int $productId, int $categoryId): void
    {
        // Update main category
        $this->db->update('catalog_products', [
            'category_id' => $categoryId,
            'updated_at'  => date('Y-m-d H:i:s'),
        ], 'id = ?', [$productId]);

        // Also add to many-to-many (ignore duplicate)
        try {
            $this->db->query(
                'INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?, ?)',
                [$productId, $categoryId]
            );
        } catch (\Throwable) {
            // Ignore duplicate
        }
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9\-\s]/u', '', $text) ?? $text;
        $text = preg_replace('/[\s\-]+/', '-', $text) ?? $text;
        return trim($text, '-');
    }
}
