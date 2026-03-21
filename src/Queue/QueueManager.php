<?php

declare(strict_types=1);

namespace App\Queue;

use App\Database;
use App\Logger;

final class QueueManager
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Add a URL to the parse queue (skip if already exists and not in error state).
     */
    public function enqueue(int $supplierId, string $url, string $type = 'product', int $priority = 5, array $payload = []): int
    {
        $existing = $this->db->fetchOne(
            'SELECT id, status FROM parse_queue WHERE supplier_id = ? AND url = ? AND type = ?',
            [$supplierId, $url, $type]
        );

        if ($existing) {
            // Re-queue if it was in error state
            if ($existing['status'] === 'error') {
                $this->db->update('parse_queue', [
                    'status'      => 'new',
                    'retry_count' => 0,
                    'updated_at'  => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existing['id']]);
                return (int)$existing['id'];
            }
            return (int)$existing['id'];
        }

        return $this->db->insert('parse_queue', [
            'supplier_id'  => $supplierId,
            'url'          => $url,
            'type'         => $type,
            'priority'     => $priority,
            'status'       => 'new',
            'payload_json' => !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Bulk enqueue URLs.
     */
    public function enqueueBatch(int $supplierId, array $urls, string $type = 'product', int $priority = 5): int
    {
        $count = 0;
        foreach ($urls as $url) {
            $this->enqueue($supplierId, $url, $type, $priority);
            $count++;
        }
        $this->logger->info("Enqueued {$count} {$type} URLs for supplier #{$supplierId}");
        return $count;
    }

    /**
     * Fetch the next batch of items to process.
     */
    public function fetchBatch(int $supplierId, string $type = 'product', int $limit = 50): array
    {
        $now = date('Y-m-d H:i:s');

        $items = $this->db->fetchAll(
            "SELECT id, url, payload_json
             FROM parse_queue
             WHERE supplier_id = ?
               AND type = ?
               AND status = 'new'
               AND (scheduled_at IS NULL OR scheduled_at <= ?)
             ORDER BY priority ASC, id ASC
             LIMIT ?",
            [$supplierId, $type, $now, $limit]
        );

        // Mark as processing
        if (!empty($items)) {
            $ids = array_column($items, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->db->query(
                "UPDATE parse_queue SET status = 'processing', started_at = ? WHERE id IN ({$placeholders})",
                array_merge([$now], $ids)
            );
        }

        return $items;
    }

    /**
     * Mark a queue item as done.
     */
    public function markDone(int $id): void
    {
        $this->db->update('parse_queue', [
            'status'       => 'done',
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
    }

    /**
     * Mark a queue item as error with optional retry.
     */
    public function markError(int $id, string $errorMessage): void
    {
        $item = $this->db->fetchOne('SELECT retry_count, max_retries FROM parse_queue WHERE id = ?', [$id]);
        if ($item === null) {
            return;
        }

        $retryCount = (int)$item['retry_count'] + 1;
        $maxRetries = (int)$item['max_retries'];

        if ($retryCount < $maxRetries) {
            // Schedule for retry with exponential backoff
            $delayMinutes = (int)(2 ** $retryCount);
            $scheduledAt = date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"));

            $this->db->update('parse_queue', [
                'status'        => 'new',
                'retry_count'   => $retryCount,
                'error_message' => $errorMessage,
                'scheduled_at'  => $scheduledAt,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);

            $this->logger->warning("Queue item #{$id} retry {$retryCount}/{$maxRetries}, next at {$scheduledAt}");
        } else {
            $this->db->update('parse_queue', [
                'status'        => 'error',
                'retry_count'   => $retryCount,
                'error_message' => $errorMessage,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);

            $this->logger->error("Queue item #{$id} failed permanently after {$retryCount} retries: {$errorMessage}");
        }
    }

    /**
     * Get queue statistics.
     */
    public function getStats(int $supplierId = 0): array
    {
        $where = $supplierId > 0 ? 'WHERE supplier_id = ?' : '';
        $params = $supplierId > 0 ? [$supplierId] : [];

        return $this->db->fetchAll(
            "SELECT status, COUNT(*) as cnt FROM parse_queue {$where} GROUP BY status",
            $params
        );
    }

    /**
     * Reset stuck processing items (older than given minutes).
     */
    public function resetStuck(int $minutes = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
        $stmt = $this->db->query(
            "UPDATE parse_queue SET status = 'new', updated_at = NOW()
             WHERE status = 'processing' AND started_at < ?",
            [$cutoff]
        );
        $count = $stmt->rowCount();
        if ($count > 0) {
            $this->logger->warning("Reset {$count} stuck queue items");
        }
        return $count;
    }
}
