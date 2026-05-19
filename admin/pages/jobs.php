<?php
$perPage = 30;
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;
$total = (int)$db->fetchColumn("SELECT COUNT(*) FROM job_runs");

$jobs = $db->fetchAll(
    "SELECT jr.*, s.code AS supplier_code, s.name AS supplier_name
     FROM job_runs jr LEFT JOIN suppliers s ON s.id = jr.supplier_id
     ORDER BY jr.started_at DESC LIMIT {$perPage} OFFSET {$offset}"
);
?>

<h4 class="mb-3"><?= t('job_runs') ?> <small class="text-muted">(<?= format_number($total) ?>)</small></h4>

<table class="table table-sm table-hover">
    <thead><tr><th>ID</th><th>Supplier</th><th>Type</th><th>Status</th><th>PID</th><th>Started</th><th>Duration</th><th>Result</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $job): ?>
        <tr>
            <td><?= $job['id'] ?></td>
            <td><code><?= esc($job['supplier_code'] ?? '—') ?></code></td>
            <td><?= esc($job['job_type']) ?></td>
            <td><?= badge($job['status']) ?></td>
            <td><small><?= $job['pid'] ?? '—' ?></small></td>
            <td><?= esc($job['started_at']) ?></td>
            <td><?php
                if ($job['finished_at']) echo (strtotime($job['finished_at']) - strtotime($job['started_at'])) . 's';
                elseif ($job['status'] === 'running') echo (time() - strtotime($job['started_at'])) . 's...';
            ?></td>
            <td><small><?= esc(mb_substr($job['result_json'] ?? $job['error_message'] ?? '', 0, 80)) ?></small></td>
            <td>
                <?php if ($job['status'] === 'running'): ?>
                    <button class="btn btn-outline-danger btn-xs" onclick="stopJob(<?= $job['id'] ?>)">Stop</button>
                <?php endif; ?>
                <?php $logFile = dirname(__DIR__, 2) . '/logs/job_' . $job['id'] . '.log';
                if (file_exists($logFile)): ?>
                    <a href="<?= url('logs', ['file' => 'job_' . $job['id'] . '.log']) ?>" class="btn btn-outline-secondary btn-xs">Log</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= pagination($total, $perPage, $currentPage, url('jobs')) ?>
