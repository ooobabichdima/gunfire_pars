<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Logger;
use App\Suppliers\SupplierParserInterface;

final class OfferUpdater
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Update prices for active offers of a supplier (lightweight check).
     * Does NOT update descriptions, images, or categories.
     */
    public function updatePrices(SupplierParserInterface $parser, int $limit = 100, int $offset = 0): array
    {
        $supplierId = $parser->getSupplierId();

        $offers = $this->db->fetchAll(
            'SELECT id, url, price_purchase, price_regular, currency, availability, is_active
             FROM supplier_offers
             WHERE supplier_id = ? AND is_active = 1
             ORDER BY last_price_check_at ASC, id ASC
             LIMIT ? OFFSET ?',
            [$supplierId, $limit, $offset]
        );

        $stats = [
            'total'      => count($offers),
            'updated'    => 0,
            'unchanged'  => 0,
            'deactivated' => 0,
            'errors'     => 0,
        ];

        foreach ($offers as $offer) {
            try {
                $this->updateSingleOffer($parser, $offer, $stats);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logger->error("Error updating offer #{$offer['id']}: {$e->getMessage()}");
            }
        }

        $this->logger->info("Price update complete", $stats);
        return $stats;
    }

    /**
     * Update a single offer by ID.
     */
    public function updateOfferById(SupplierParserInterface $parser, int $offerId): bool
    {
        $offer = $this->db->fetchOne(
            'SELECT id, url, price_purchase, price_regular, currency, availability, is_active
             FROM supplier_offers WHERE id = ?',
            [$offerId]
        );

        if ($offer === null) {
            $this->logger->warning("Offer #{$offerId} not found");
            return false;
        }

        $stats = ['updated' => 0, 'unchanged' => 0, 'deactivated' => 0, 'errors' => 0];
        $this->updateSingleOffer($parser, $offer, $stats);
        return $stats['updated'] > 0 || $stats['deactivated'] > 0;
    }

    private function updateSingleOffer(SupplierParserInterface $parser, array $offer, array &$stats): void
    {
        $now = date('Y-m-d H:i:s');

        $priceData = $parser->checkPrice($offer['url']);

        if ($priceData === null) {
            // Timeout or network error — skip, don't deactivate
            $stats['errors']++;
            $this->logger->warning("Could not check price for offer #{$offer['id']}");
            return;
        }

        // Product gone (404)
        if ($priceData['is_active'] === false) {
            $this->db->update('supplier_offers', [
                'is_active'           => 0,
                'last_price_check_at' => $now,
                'updated_at'          => $now,
            ], 'id = ?', [$offer['id']]);

            $this->recordPriceHistory($offer['id'], $priceData, false);
            $stats['deactivated']++;
            $this->logger->info("Deactivated offer #{$offer['id']} (404)");
            return;
        }

        // Check if price changed
        $priceChanged = (
            (float)($offer['price_purchase'] ?? 0) !== (float)($priceData['price_purchase'] ?? 0)
            || (float)($offer['price_regular'] ?? 0) !== (float)($priceData['price_regular'] ?? 0)
            || ($offer['availability'] ?? '') !== ($priceData['availability'] ?? '')
        );

        $updateData = [
            'price_purchase'      => $priceData['price_purchase'],
            'price_regular'       => $priceData['price_regular'],
            'currency'            => $priceData['currency'],
            'availability'        => $priceData['availability'],
            'is_active'           => 1,
            'last_price_check_at' => $now,
            'last_seen_at'        => $now,
            'updated_at'          => $now,
        ];

        $this->db->update('supplier_offers', $updateData, 'id = ?', [$offer['id']]);

        if ($priceChanged) {
            $this->recordPriceHistory($offer['id'], $priceData, true);
            $this->generateAlerts($offer, $priceData);
            $stats['updated']++;
            $this->logger->info("Price updated for offer #{$offer['id']}: {$offer['price_purchase']} → {$priceData['price_purchase']}");
        } else {
            $stats['unchanged']++;
        }
    }

    private function recordPriceHistory(int $offerId, array $priceData, bool $isActive): void
    {
        $this->db->insert('supplier_offer_price_history', [
            'supplier_offer_id' => $offerId,
            'price_purchase'    => $priceData['price_purchase'],
            'price_regular'     => $priceData['price_regular'],
            'currency'          => $priceData['currency'] ?? 'PLN',
            'availability'      => $priceData['availability'],
            'is_active'         => $isActive ? 1 : 0,
            'checked_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    private function generateAlerts(array $offer, array $priceData): void
    {
        try {
            $oldPrice = (float)($offer['price_purchase'] ?? 0);
            $newPrice = (float)($priceData['price_purchase'] ?? 0);
            $oldAvail = $offer['availability'] ?? '';
            $newAvail = $priceData['availability'] ?? '';

            $supplierId = (int)($offer['supplier_id'] ?? 0);
            $catalogId = !empty($offer['catalog_product_id']) ? (int)$offer['catalog_product_id'] : null;
            $offerName = mb_substr($offer['name'] ?? "Offer #{$offer['id']}", 0, 80);

            // Price drop > 5%
            if ($oldPrice > 0 && $newPrice > 0 && $newPrice < $oldPrice) {
                $dropPct = (($oldPrice - $newPrice) / $oldPrice) * 100;
                if ($dropPct >= 5) {
                    $this->db->insert('price_alerts', [
                        'catalog_product_id' => $catalogId,
                        'supplier_id'        => $supplierId,
                        'alert_type'         => 'price_drop',
                        'threshold_percent'  => round($dropPct, 2),
                        'old_value'          => number_format($oldPrice, 2),
                        'new_value'          => number_format($newPrice, 2),
                        'message'            => "{$offerName}: price dropped " . round($dropPct, 1) . "%",
                        'created_at'         => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            // Price increase > 10%
            if ($oldPrice > 0 && $newPrice > 0 && $newPrice > $oldPrice) {
                $incPct = (($newPrice - $oldPrice) / $oldPrice) * 100;
                if ($incPct >= 10) {
                    $this->db->insert('price_alerts', [
                        'catalog_product_id' => $catalogId,
                        'supplier_id'        => $supplierId,
                        'alert_type'         => 'price_increase',
                        'threshold_percent'  => round($incPct, 2),
                        'old_value'          => number_format($oldPrice, 2),
                        'new_value'          => number_format($newPrice, 2),
                        'message'            => "{$offerName}: price increased " . round($incPct, 1) . "%",
                        'created_at'         => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            // Out of stock
            if ($oldAvail === 'in_stock' && $newAvail === 'out_of_stock') {
                $this->db->insert('price_alerts', [
                    'catalog_product_id' => $catalogId,
                    'supplier_id'        => $supplierId,
                    'alert_type'         => 'out_of_stock',
                    'old_value'          => $oldAvail,
                    'new_value'          => $newAvail,
                    'message'            => "{$offerName}: went out of stock",
                    'created_at'         => date('Y-m-d H:i:s'),
                ]);
            }

            // Back in stock
            if ($oldAvail === 'out_of_stock' && $newAvail === 'in_stock') {
                $this->db->insert('price_alerts', [
                    'catalog_product_id' => $catalogId,
                    'supplier_id'        => $supplierId,
                    'alert_type'         => 'back_in_stock',
                    'old_value'          => $oldAvail,
                    'new_value'          => $newAvail,
                    'message'            => "{$offerName}: back in stock",
                    'created_at'         => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->debug("Alert generation failed: {$e->getMessage()}");
        }
    }
}
