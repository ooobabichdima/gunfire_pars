<?php

declare(strict_types=1);

namespace App\Admin;

use App\Database;
use App\Logger;

final class JobRunner
{
    private Database $db;
    private Logger $logger;
    private string $basePath;

    private const JOB_SCRIPTS = [
        'scan'      => 'scan_suppliers.php',
        'parse'     => 'parse_offers.php',
        'match'     => 'match_products.php',
        'relations' => 'build_relations.php',
        'prices'    => 'update_prices.php',
    ];

    public function __construct(Database $db, Logger $logger, string $basePath)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->basePath = rtrim($basePath, '/');
    }

    public function startJob(int $supplierId, string $jobType, array $params = []): array
    {
        $script = self::JOB_SCRIPTS[$jobType] ?? null;
        if ($script === null) {
            throw new \InvalidArgumentException("Unknown job type: {$jobType}");
        }

        $running = $this->db->fetchOne(
            "SELECT id FROM job_runs WHERE supplier_id = ? AND job_type = ? AND status = 'running'",
            [$supplierId, $jobType]
        );
        if ($running) {
            throw new \RuntimeException("Job '{$jobType}' is already running for this supplier (#{$running['id']})");
        }

        $supplier = $this->db->fetchOne('SELECT code FROM suppliers WHERE id = ?', [$supplierId]);
        if (!$supplier) {
            throw new \RuntimeException("Supplier #{$supplierId} not found");
        }

        $jobRunId = $this->db->insert('job_runs', [
            'supplier_id'  => $supplierId,
            'job_type'     => $jobType,
            'status'       => 'running',
            'params_json'  => json_encode($params),
            'started_at'   => date('Y-m-d H:i:s'),
        ]);

        $cmd = $this->buildCommand($script, $supplier['code'], $jobType, $params, $jobRunId);

        $pid = $this->execBackground($cmd);

        $this->db->update('job_runs', ['pid' => $pid], 'id = ?', [$jobRunId]);

        $this->logger->info("Started job #{$jobRunId}: {$jobType} for {$supplier['code']} (PID: {$pid})");

        return ['job_run_id' => $jobRunId, 'pid' => $pid, 'status' => 'running'];
    }

    public function stopJob(int $jobRunId): bool
    {
        $job = $this->db->fetchOne("SELECT * FROM job_runs WHERE id = ?", [$jobRunId]);
        if (!$job || $job['status'] !== 'running') {
            return false;
        }

        $pid = (int)$job['pid'];
        if ($pid > 0 && $this->isProcessRunning($pid)) {
            posix_kill($pid, SIGTERM);
            usleep(500000);
            if ($this->isProcessRunning($pid)) {
                posix_kill($pid, SIGKILL);
            }
        }

        $this->db->update('job_runs', [
            'status'      => 'cancelled',
            'finished_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$jobRunId]);

        $this->logger->info("Stopped job #{$jobRunId}");
        return true;
    }

    public function checkJobStatus(int $jobRunId): ?array
    {
        $job = $this->db->fetchOne("SELECT * FROM job_runs WHERE id = ?", [$jobRunId]);
        if (!$job) {
            return null;
        }

        if ($job['status'] === 'running') {
            $pid = (int)$job['pid'];
            if ($pid > 0 && !$this->isProcessRunning($pid)) {
                $this->db->update('job_runs', [
                    'status'      => 'completed',
                    'finished_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$jobRunId]);
                $job['status'] = 'completed';
            }
        }

        return $job;
    }

    public function cleanupStaleJobs(int $minutesThreshold = 120): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutesThreshold} minutes"));
        $stale = $this->db->fetchAll(
            "SELECT id, pid FROM job_runs WHERE status = 'running' AND started_at < ?",
            [$cutoff]
        );

        $count = 0;
        foreach ($stale as $job) {
            $pid = (int)$job['pid'];
            if ($pid <= 0 || !$this->isProcessRunning($pid)) {
                $this->db->update('job_runs', [
                    'status'      => 'failed',
                    'error_message' => 'Process terminated unexpectedly',
                    'finished_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$job['id']]);
                $count++;
            }
        }

        return $count;
    }

    public function getRecentJobs(int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT jr.*, s.code AS supplier_code, s.name AS supplier_name
             FROM job_runs jr
             LEFT JOIN suppliers s ON s.id = jr.supplier_id
             ORDER BY jr.started_at DESC LIMIT ?",
            [$limit]
        );
    }

    private function buildCommand(string $script, string $supplierCode, string $jobType, array $params, int $jobRunId): string
    {
        $php = PHP_BINARY;
        $scriptPath = $this->basePath . '/' . $script;

        $args = ["--supplier={$supplierCode}"];

        if (isset($params['batch_size'])) {
            $args[] = '--limit=' . (int)$params['batch_size'];
        }
        if (isset($params['offset'])) {
            $args[] = '--offset=' . (int)$params['offset'];
        }
        if ($jobType === 'prices' && isset($params['mode'])) {
            $args[] = '--mode=' . $params['mode'];
        }

        $argStr = implode(' ', $args);
        $logFile = $this->basePath . '/logs/job_' . $jobRunId . '.log';

        return "{$php} {$scriptPath} {$argStr} > " . escapeshellarg($logFile) . " 2>&1";
    }

    private function execBackground(string $cmd): int
    {
        $fullCmd = "nohup {$cmd} & echo $!";
        $output = [];
        exec($fullCmd, $output);
        return (int)($output[0] ?? 0);
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        return posix_kill($pid, 0);
    }
}
