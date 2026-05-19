<?php

declare(strict_types=1);

/**
 * cron_scheduler.php — Run every minute via system cron.
 * Checks supplier_schedules and launches due jobs.
 *
 * System cron:
 *   * * * * * php /path/to/cron_scheduler.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\Lock;
use App\Logger;
use App\Admin\JobRunner;

$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'scheduler');

$lock = new Lock($config['parser']['lock_dir'], 'cron_scheduler');
if (!$lock->acquire()) {
    exit(0);
}

$db = Database::getInstance($config['db']);
$runner = new JobRunner($db, $logger, __DIR__);

$runner->cleanupStaleJobs();

$now = new \DateTimeImmutable();
$nowStr = $now->format('Y-m-d H:i:s');

$dueSchedules = $db->fetchAll(
    "SELECT ss.*, s.code AS supplier_code
     FROM supplier_schedules ss
     JOIN suppliers s ON s.id = ss.supplier_id AND s.is_active = 1
     WHERE ss.is_enabled = 1
       AND (ss.next_run_at IS NULL OR ss.next_run_at <= ?)",
    [$nowStr]
);

foreach ($dueSchedules as $schedule) {
    $running = $db->fetchOne(
        "SELECT id FROM job_runs WHERE supplier_id = ? AND job_type = ? AND status = 'running'",
        [$schedule['supplier_id'], $schedule['job_type']]
    );

    if ($running) {
        $logger->debug("Skipping {$schedule['supplier_code']}/{$schedule['job_type']} — already running (#{$running['id']})");
        continue;
    }

    try {
        $result = $runner->startJob(
            (int)$schedule['supplier_id'],
            $schedule['job_type'],
            ['batch_size' => (int)$schedule['batch_size']]
        );
        $logger->info("Launched scheduled job: {$schedule['supplier_code']}/{$schedule['job_type']} (job #{$result['job_run_id']})");
    } catch (\Throwable $e) {
        $logger->error("Failed to launch {$schedule['supplier_code']}/{$schedule['job_type']}: {$e->getMessage()}");
    }

    // Compute next run
    $nextRun = computeNextRun($schedule['cron_expression'], $now);
    $db->update('supplier_schedules', [
        'last_run_at' => $nowStr,
        'next_run_at' => $nextRun?->format('Y-m-d H:i:s'),
        'updated_at'  => $nowStr,
    ], 'id = ?', [$schedule['id']]);
}

$lock->release();

/**
 * Simple cron expression parser (minute hour day month weekday).
 */
function computeNextRun(string $cron, \DateTimeImmutable $from): ?\DateTimeImmutable
{
    $parts = preg_split('/\s+/', trim($cron));
    if (count($parts) !== 5) {
        return $from->modify('+1 hour');
    }

    [$minExpr, $hourExpr, $dayExpr, $monthExpr, $wdayExpr] = $parts;

    $candidate = $from->modify('+1 minute');
    $candidate = $candidate->setTime((int)$candidate->format('H'), (int)$candidate->format('i'), 0);

    for ($i = 0; $i < 1440 * 31; $i++) {
        $min = (int)$candidate->format('i');
        $hour = (int)$candidate->format('G');
        $day = (int)$candidate->format('j');
        $month = (int)$candidate->format('n');
        $wday = (int)$candidate->format('w');

        if (cronFieldMatches($minExpr, $min, 0, 59)
            && cronFieldMatches($hourExpr, $hour, 0, 23)
            && cronFieldMatches($dayExpr, $day, 1, 31)
            && cronFieldMatches($monthExpr, $month, 1, 12)
            && cronFieldMatches($wdayExpr, $wday, 0, 6)) {
            return $candidate;
        }

        $candidate = $candidate->modify('+1 minute');
    }

    return $from->modify('+1 hour');
}

function cronFieldMatches(string $expr, int $value, int $min, int $max): bool
{
    if ($expr === '*') {
        return true;
    }

    foreach (explode(',', $expr) as $part) {
        $part = trim($part);

        // */N step
        if (str_starts_with($part, '*/')) {
            $step = (int)substr($part, 2);
            if ($step > 0 && ($value % $step) === 0) {
                return true;
            }
            continue;
        }

        // Range N-M
        if (str_contains($part, '-')) {
            [$lo, $hi] = explode('-', $part, 2);
            if ($value >= (int)$lo && $value <= (int)$hi) {
                return true;
            }
            continue;
        }

        // Exact
        if ((int)$part === $value) {
            return true;
        }
    }

    return false;
}
