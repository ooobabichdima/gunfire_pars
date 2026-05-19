<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Database;
use App\Logger;

final class ProductMatcher
{
    private const FUZZY_THRESHOLD = 0.85;

    private Database $db;
    private Logger $logger;
    private ProductNormalizer $normalizer;

    public function __construct(Database $db, Logger $logger, ProductNormalizer $normalizer)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->normalizer = $normalizer;
    }

    public function matchOrCreate(array $offerData): int
    {
        // Step 1: Match by EAN (exact, reliable)
        if (!empty($offerData['ean'])) {
            $match = $this->matchByEan($offerData['ean']);
            if ($match) {
                $this->logger->debug("Matched by EAN: {$offerData['ean']} → catalog #{$match}");
                return $match;
            }
        }

        // Step 2: Match by SKU (exact)
        if (!empty($offerData['external_sku'])) {
            $match = $this->matchBySku($offerData['external_sku']);
            if ($match) {
                $this->logger->debug("Matched by SKU: {$offerData['external_sku']} → catalog #{$match}");
                return $match;
            }
        }

        $normalized = $this->normalizer->normalize($offerData['name'] ?? '', $offerData['brand'] ?? '');

        // Step 3: Match by brand + FULL model (exact model match only)
        if (!empty($normalized['brand']) && !empty($normalized['model'])) {
            $match = $this->matchByBrandModel($normalized['brand'], $normalized['model']);
            if ($match) {
                $this->logger->debug("Matched by brand+model: {$normalized['brand']} {$normalized['model']} → catalog #{$match}");
                return $match;
            }
        }

        // Step 4: Fuzzy match by normalized_name (with model guard)
        if (!empty($normalized['normalized_name'])) {
            $match = $this->matchByFuzzyName($normalized['normalized_name'], $normalized['brand'], $normalized['model']);
            if ($match) {
                $this->logger->debug("Matched by fuzzy name → catalog #{$match}");
                return $match;
            }
        }

        // Step 5: Create new catalog product
        return $this->createCatalogProduct($offerData, $normalized);
    }

    public function matchUnlinked(int $limit = 500, int $offset = 0): array
    {
        $offers = $this->db->fetchAll(
            'SELECT id, name, brand, model, ean, external_sku
             FROM supplier_offers
             WHERE catalog_product_id IS NULL AND is_active = 1
             ORDER BY id ASC
             LIMIT ? OFFSET ?',
            [$limit, $offset]
        );

        $stats = ['matched' => 0, 'created' => 0, 'total' => count($offers)];

        foreach ($offers as $offer) {
            $catalogId = $this->matchOrCreate($offer);

            $this->db->update(
                'supplier_offers',
                ['catalog_product_id' => $catalogId, 'updated_at' => date('Y-m-d H:i:s')],
                'id = ?',
                [$offer['id']]
            );

            $stats['matched']++;
        }

        return $stats;
    }

    // ==================================================================
    // Match strategies
    // ==================================================================

    private function matchByEan(string $ean): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM catalog_products WHERE ean = ? LIMIT 1',
            [$ean]
        );
        return $row ? (int)$row['id'] : null;
    }

    private function matchBySku(string $sku): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM catalog_products WHERE sku = ? LIMIT 1',
            [$sku]
        );
        return $row ? (int)$row['id'] : null;
    }

    private function matchByBrandModel(string $brand, string $model): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM catalog_products WHERE brand = ? AND model = ? LIMIT 1',
            [$brand, $model]
        );
        return $row ? (int)$row['id'] : null;
    }

    private function matchByFuzzyName(string $normalizedName, string $brand = '', string $offerModel = ''): ?int
    {
        if (!empty($brand)) {
            $candidates = $this->db->fetchAll(
                'SELECT id, normalized_name, model FROM catalog_products WHERE brand = ? LIMIT 200',
                [$brand]
            );
        } else {
            $words = explode(' ', $normalizedName);
            $firstWord = $words[0] ?? '';
            if (mb_strlen($firstWord) < 3) {
                return null;
            }
            $candidates = $this->db->fetchAll(
                'SELECT id, normalized_name, model FROM catalog_products WHERE normalized_name LIKE ? LIMIT 200',
                [$firstWord . '%']
            );
        }

        $bestId = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            // MODEL GUARD: if both have model numbers and they differ → skip
            $candidateModel = $candidate['model'] ?? '';
            if (!empty($offerModel) && !empty($candidateModel)) {
                if (!$this->modelsCompatible($offerModel, $candidateModel)) {
                    continue;
                }
            }

            $score = $this->normalizer->similarity($normalizedName, $candidate['normalized_name']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = (int)$candidate['id'];
            }
        }

        if ($bestScore >= self::FUZZY_THRESHOLD && $bestId !== null) {
            return $bestId;
        }

        return null;
    }

    /**
     * Check if two model identifiers are compatible (same product).
     * SA-E25 vs SA-E25 → true (same)
     * SA-E25 vs SA-E24 → false (different models)
     * SA-E17 vs SA-E17 → true
     * M4A1 vs M4A1 → true
     * M4A1 vs M16A4 → false
     */
    private function modelsCompatible(string $a, string $b): bool
    {
        $a = strtoupper(trim($a));
        $b = strtoupper(trim($b));

        if ($a === $b) {
            return true;
        }

        // Normalize separators
        $normA = preg_replace('/[\-_\.\s]+/', '', $a);
        $normB = preg_replace('/[\-_\.\s]+/', '', $b);

        if ($normA === $normB) {
            return true;
        }

        // If they contain digits and the digit parts differ → different models
        $digitsA = preg_replace('/[^\d]/', '', $a);
        $digitsB = preg_replace('/[^\d]/', '', $b);
        if (!empty($digitsA) && !empty($digitsB) && $digitsA !== $digitsB) {
            return false;
        }

        // If letter+number combos differ → different
        // E25 vs E24, E17 vs E18, etc.
        preg_match_all('/[A-Z]+\d+/', $a, $partsA);
        preg_match_all('/[A-Z]+\d+/', $b, $partsB);
        if (!empty($partsA[0]) && !empty($partsB[0])) {
            $setA = $partsA[0];
            $setB = $partsB[0];
            sort($setA);
            sort($setB);
            if ($setA !== $setB) {
                return false;
            }
        }

        return true;
    }

    // ==================================================================
    // Create new catalog product
    // ==================================================================

    private function createCatalogProduct(array $offerData, array $normalized): int
    {
        $now = date('Y-m-d H:i:s');

        $id = $this->db->insert('catalog_products', [
            'brand'           => $normalized['brand'] ?: ($offerData['brand'] ?? null),
            'name'            => $offerData['name'] ?? '',
            'model'           => $normalized['model'] ?: ($offerData['model'] ?? null),
            'sku'             => $offerData['external_sku'] ?? null,
            'ean'             => !empty($offerData['ean']) ? $offerData['ean'] : null,
            'normalized_name' => $normalized['normalized_name'],
            'is_bundle'       => $offerData['is_bundle'] ?? 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $this->logger->info("Created catalog product #{$id}: {$offerData['name']}");

        return $id;
    }
}
