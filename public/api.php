<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/bootstrap.php';

use App\Admin\Auth;
use App\Admin\JobRunner;

header('Content-Type: application/json');

$auth = new Auth($db);
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

$runner = new JobRunner($db, $logger, dirname(__DIR__));

try {
    $result = match ($action) {
        'run_job' => $runner->startJob(
            (int)($input['supplier_id'] ?? 0),
            $input['job_type'] ?? '',
            ['batch_size' => (int)($input['batch_size'] ?? 50)]
        ),

        'stop_job' => ['success' => $runner->stopJob((int)($input['job_run_id'] ?? 0))],

        'job_status' => $runner->checkJobStatus((int)($input['job_run_id'] ?? $_GET['job_run_id'] ?? 0)),

        'toggle_supplier' => (function () use ($db, $input) {
            $db->update('suppliers',
                ['is_active' => (int)($input['is_active'] ?? 0)],
                'id = ?', [(int)$input['supplier_id']]
            );
            return ['success' => true];
        })(),

        'toggle_schedule' => (function () use ($db, $input) {
            $db->update('supplier_schedules',
                ['is_enabled' => (int)($input['is_enabled'] ?? 0)],
                'id = ?', [(int)$input['schedule_id']]
            );
            return ['success' => true];
        })(),

        'retry_queue' => (function () use ($db, $input) {
            $ids = $input['ids'] ?? [];
            if (empty($ids)) return ['count' => 0];
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->query(
                "UPDATE parse_queue SET status = 'new', retry_count = 0, error_message = NULL WHERE id IN ({$placeholders})",
                array_map('intval', $ids)
            );
            return ['count' => count($ids)];
        })(),

        'reset_stuck' => (function () use ($db, $input) {
            $minutes = (int)($input['minutes'] ?? 30);
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
            $stmt = $db->query(
                "UPDATE parse_queue SET status = 'new' WHERE status = 'processing' AND started_at < ?",
                [$cutoff]
            );
            return ['count' => $stmt->rowCount()];
        })(),

        'queue_stats' => (function () use ($db, $input) {
            $supplierId = (int)($input['supplier_id'] ?? $_GET['supplier_id'] ?? 0);
            $where = $supplierId > 0 ? 'WHERE supplier_id = ?' : '';
            $params = $supplierId > 0 ? [$supplierId] : [];
            return $db->fetchAll("SELECT status, COUNT(*) as cnt FROM parse_queue {$where} GROUP BY status", $params);
        })(),

        'dashboard_stats' => (function () use ($db, $runner) {
            $runner->cleanupStaleJobs();
            return [
                'suppliers' => $db->fetchAll(
                    "SELECT s.*,
                        (SELECT COUNT(*) FROM supplier_offers WHERE supplier_id=s.id AND is_active=1) as offer_count,
                        (SELECT MAX(last_seen_at) FROM supplier_offers WHERE supplier_id=s.id) as last_activity
                     FROM suppliers s ORDER BY s.id"
                ),
                'queue' => $db->fetchAll("SELECT supplier_id, status, COUNT(*) as cnt FROM parse_queue GROUP BY supplier_id, status"),
                'recent_jobs' => $runner->getRecentJobs(5),
            ];
        })(),

        default => throw new \InvalidArgumentException("Unknown action: {$action}"),
    };

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
