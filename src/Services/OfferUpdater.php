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
}
