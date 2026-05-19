<?php
$suppliers = $db->fetchAll("SELECT * FROM suppliers ORDER BY id");
$schedules = $db->fetchAll(
    "SELECT ss.*, s.code AS supplier_code FROM supplier_schedules ss
     JOIN suppliers s ON s.id = ss.supplier_id ORDER BY ss.supplier_id, ss.job_type"
);
$scheduleMap = [];
foreach ($schedules as $s) {
    $scheduleMap[$s['supplier_id']][$s['job_type']] = $s;
}

$jobTypes = ['scan', 'parse', 'match', 'relations', 'prices'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    foreach ($suppliers as $sup) {
        foreach ($jobTypes as $jt) {
            $key = "sched_{$sup['id']}_{$jt}";
            $cron = trim($_POST[$key . '_cron'] ?? '');
            $batch = (int)($_POST[$key . '_batch'] ?? 50);
            $enabled = isset($_POST[$key . '_enabled']) ? 1 : 0;

            if (empty($cron)) continue;

            $db->query(
                "INSERT INTO supplier_schedules (supplier_id, job_type, cron_expression, batch_size, is_enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE cron_expression=VALUES(cron_expression), batch_size=VALUES(batch_size), is_enabled=VALUES(is_enabled), updated_at=NOW()",
                [$sup['id'], $jt, $cron, $batch, $enabled]
            );
        }
    }
    flash_set('success', 'Schedules saved');
    header('Location: ' . url('schedules'));
    exit;
}

$defaultCrons = [
    'scan'      => '0 2 * * *',
    'parse'     => '*/15 * * * *',
    'match'     => '0 * * * *',
    'relations' => '0 4 * * *',
    'prices'    => '*/30 * * * *',
];
?>

<h4 class="mb-3">Schedules</h4>
<p class="text-muted small">Configure cron schedules per supplier per job type. Add <code>* * * * * php <?= dirname(__DIR__, 2) ?>/cron_scheduler.php</code> to system cron.</p>

<form method="POST">
    <?= csrf_field() ?>

    <?php foreach ($suppliers as $sup): ?>
        <div class="card mb-3">
            <div class="card-header"><strong><?= esc($sup['name']) ?></strong> <code><?= esc($sup['code']) ?></code> <?= $sup['is_active'] ? badge('site') : badge('skipped') ?></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:120px">Job</th><th style="width:200px">Cron Expression</th><th style="width:100px">Batch</th><th style="width:80px">Enabled</th><th>Last Run</th><th>Next Run</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($jobTypes as $jt):
                        $s = $scheduleMap[$sup['id']][$jt] ?? null;
                        $key = "sched_{$sup['id']}_{$jt}";
                    ?>
                        <tr>
                            <td><code><?= $jt ?></code></td>
                            <td><input type="text" name="<?= $key ?>_cron" class="form-control form-control-sm" value="<?= esc($s['cron_expression'] ?? $defaultCrons[$jt] ?? '') ?>" placeholder="*/15 * * * *"></td>
                            <td><input type="number" name="<?= $key ?>_batch" class="form-control form-control-sm" value="<?= $s['batch_size'] ?? 50 ?>"></td>
                            <td><input type="checkbox" name="<?= $key ?>_enabled" class="form-check-input" <?= ($s['is_enabled'] ?? 0) ? 'checked' : '' ?>></td>
                            <td><small><?= time_ago($s['last_run_at'] ?? null) ?></small></td>
                            <td><small><?= esc($s['next_run_at'] ?? '—') ?></small></td>
                            <td><button type="button" class="btn btn-outline-primary btn-xs" onclick="runJob(<?= $sup['id'] ?>, '<?= $jt ?>')">Run Now</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-primary">Save Schedules</button>
</form>
