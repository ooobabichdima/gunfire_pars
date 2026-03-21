<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Logger;

final class PriceComparator
{
    private Database $db;
    private Logger $logger;

    /** Default delivery costs per supplier if not set on offer level. */
    private array $defaultDeliveryCosts = [];

    /** Additional markups per supplier (e.g., commission %). */
    private array $supplierMarkups = [];

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Set default delivery cost for a supplier.
     */
    public function setDefaultDeliveryCost(string $supplierCode, float $cost): void
    {
        $this->defaultDeliveryCosts[$supplierCode] = $cost;
    }

    /**
     * Set a percentage markup for a supplier (e.g., commission).
     */
    public function setSupplierMarkup(string $supplierCode, float $markupPercent): void
    {
        $this->supplierMarkups[$supplierCode] = $markupPercent;
    }

    /**
     * Compare all offers for a single catalog product.
     *
     * @return array{
     *   catalog_product_id: int,
     *   product_name: string,
     *   best_offer: ?array,
     *   offers: array
     * }
     */
    public function compareForProduct(int $catalogProductId): array
    {
        $product = $this->db->fetchOne(
            'SELECT id, name, brand, model FROM catalog_products WHERE id = ?',
            [$catalogProductId]
        );

        if ($product === null) {
            return [
                'catalog_product_id' => $catalogProductId,
                'product_name'       => '',
                'best_offer'         => null,
                'offers'             => [],
            ];
        }

        $offers = $this->db->fetchAll(
            "SELECT so.*, s.code AS supplier_code, s.name AS supplier_name
             FROM supplier_offers so
             JOIN suppliers s ON s.id = so.supplier_id
             WHERE so.catalog_product_id = ?
               AND so.is_active = 1
               AND so.price_purchase IS NOT NULL
             ORDER BY so.price_purchase ASC",
            [$catalogProductId]
        );

        $evaluatedOffers = [];
        foreach ($offers as $offer) {
            $evaluated = $this->evaluateOffer($offer);
            $evaluatedOffers[] = $evaluated;
        }

        // Sort by effective price
        usort($evaluatedOffers, fn($a, $b) => $a['effective_price'] <=> $b['effective_price']);

        $bestOffer = !empty($evaluatedOffers) ? $evaluatedOffers[0] : null;

        return [
            'catalog_product_id' => $catalogProductId,
            'product_name'       => $product['name'],
            'brand'              => $product['brand'],
            'model'              => $product['model'],
            'best_offer'         => $bestOffer,
            'offers'             => $evaluatedOffers,
            'offer_count'        => count($evaluatedOffers),
        ];
    }

    /**
     * Compare prices across all products that have offers from multiple suppliers.
     */
    public function compareAll(int $limit = 100, int $offset = 0): array
    {
        $productIds = $this->db->fetchAll(
            "SELECT catalog_product_id, COUNT(DISTINCT supplier_id) as supplier_count
             FROM supplier_offers
             WHERE catalog_product_id IS NOT NULL
               AND is_active = 1
               AND price_purchase IS NOT NULL
             GROUP BY catalog_product_id
             HAVING supplier_count > 1
             ORDER BY catalog_product_id ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        $results = [];
        foreach ($productIds as $row) {
            $results[] = $this->compareForProduct((int)$row['catalog_product_id']);
        }

        return $results;
    }

    /**
     * Get the best supplier for each product (summary report).
     */
    public function bestSuppliersReport(int $limit = 500, int $offset = 0): array
    {
        $products = $this->db->fetchAll(
            "SELECT DISTINCT catalog_product_id
             FROM supplier_offers
             WHERE catalog_product_id IS NOT NULL
               AND is_active = 1
               AND price_purchase IS NOT NULL
             ORDER BY catalog_product_id ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        $report = [];
        foreach ($products as $row) {
            $comparison = $this->compareForProduct((int)$row['catalog_product_id']);
            if ($comparison['best_offer'] !== null) {
                $report[] = [
                    'catalog_product_id' => $comparison['catalog_product_id'],
                    'product_name'       => $comparison['product_name'],
                    'brand'              => $comparison['brand'] ?? '',
                    'best_supplier'      => $comparison['best_offer']['supplier_name'],
                    'effective_price'    => $comparison['best_offer']['effective_price'],
                    'purchase_price'     => $comparison['best_offer']['price_purchase'],
                    'delivery_cost'      => $comparison['best_offer']['delivery_cost_applied'],
                    'offer_count'        => $comparison['offer_count'],
                ];
            }
        }

        return $report;
    }

    /**
     * Calculate effective price for an offer.
     */
    private function evaluateOffer(array $offer): array
    {
        $supplierCode = $offer['supplier_code'] ?? '';
        $pricePurchase = (float)($offer['price_purchase'] ?? 0);

        // Delivery cost: use offer-level, then default, then 0
        $deliveryCost = $offer['delivery_cost'] !== null
            ? (float)$offer['delivery_cost']
            : ($this->defaultDeliveryCosts[$supplierCode] ?? 0.0);

        // Markup (commission)
        $markupPercent = $this->supplierMarkups[$supplierCode] ?? 0.0;
        $markupAmount = $pricePurchase * ($markupPercent / 100);

        $effectivePrice = $pricePurchase + $deliveryCost + $markupAmount;

        // Savings vs regular price
        $regularPrice = (float)($offer['price_regular'] ?? $pricePurchase);
        $savings = $regularPrice > $pricePurchase ? $regularPrice - $pricePurchase : 0.0;
        $savingsPercent = $regularPrice > 0 ? ($savings / $regularPrice) * 100 : 0.0;

        return [
            'offer_id'             => (int)$offer['id'],
            'supplier_code'        => $supplierCode,
            'supplier_name'        => $offer['supplier_name'] ?? '',
            'external_id'          => $offer['external_id'] ?? '',
            'url'                  => $offer['url'] ?? '',
            'price_purchase'       => $pricePurchase,
            'price_regular'        => $regularPrice,
            'delivery_cost_applied' => $deliveryCost,
            'markup_amount'        => round($markupAmount, 2),
            'effective_price'      => round($effectivePrice, 2),
            'currency'             => $offer['currency'] ?? 'PLN',
            'availability'         => $offer['availability'] ?? 'unknown',
            'min_order_qty'        => (int)($offer['min_order_qty'] ?? 1),
            'lead_time_days'       => $offer['lead_time_days'],
            'savings'              => round($savings, 2),
            'savings_percent'      => round($savingsPercent, 1),
        ];
    }
}
