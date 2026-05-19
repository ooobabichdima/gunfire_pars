<?php
$suppliers = $db->fetchAll(
    "SELECT s.*,
        (SELECT COUNT(*) FROM supplier_offers WHERE supplier_id=s.id AND is_active=1) as offer_count,
        (SELECT COUNT(*) FROM supplier_offers WHERE supplier_id=s.id) as total_offers,
        (SELECT MAX(last_seen_at) FROM supplier_offers WHERE supplier_id=s.id) as last_activity
     FROM suppliers s ORDER BY s.id"
);

$queueStats = $db->fetchAll("SELECT status, COUNT(*) as cnt FROM parse_queue GROUP BY status");
$queueMap = array_column($queueStats, 'cnt', 'status');

$catalogCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM catalog_products");
$totalOffers = (int)$db->fetchColumn("SELECT COUNT(*) FROM supplier_offers WHERE is_active = 1");
$multiSupplier = (int)$db->fetchColumn(
    "SELECT COUNT(DISTINCT catalog_product_id) FROM supplier_offers
     WHERE catalog_product_id IS NOT NULL AND is_active = 1
     GROUP BY catalog_product_id HAVING COUNT(DISTINCT supplier_id) > 1 LIMIT 1"
) > 0 ? (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM (
        SELECT catalog_product_id FROM supplier_offers
        WHERE catalog_product_id IS NOT NULL AND is_active = 1
        GROUP BY catalog_product_id HAVING COUNT(DISTINCT supplier_id) > 1
    ) t"
) : 0;

$recentJobs = $db->fetchAll(
    "SELECT jr.*, s.code AS supplier_code FROM job_runs jr LEFT JOIN suppliers s ON s.id=jr.supplier_id ORDER BY jr.started_at DESC LIMIT 10"
);
?>

<h4 class="mb-4">Dashboard</h4>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card primary">
            <div class="card-body py-3">
                <div class="text-muted small">Catalog Products</div>
                <div class="fs-4 fw-bold"><?= format_number($catalogCount) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="card-body py-3">
                <div class="text-muted small">Active Offers</div>
                <div class="fs-4 fw-bold"><?= format_number($totalOffers) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="card-body py-3">
                <div class="text-muted small">Queue Pending</div>
                <div class="fs-4 fw-bold"><?= format_number(($queueMap['new'] ?? 0) + ($queueMap['processing'] ?? 0)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card danger">
            <div class="card-body py-3">
                <div class="text-muted small">Queue Errors</div>
                <div class="fs-4 fw-bold"><?= format_number($queueMap['error'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($suppliers as $s): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="mb-1"><?= esc($s['name']) ?> <?= badge($s['type']) ?></h5>
                        <small class="text-muted"><?= esc($s['base_url'] ?? '') ?></small>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" <?= $s['is_active'] ? 'checked' : '' ?>
                               onchange="toggleSupplier(<?= $s['id'] ?>, this.checked)">
                    </div>
                </div>
                <div class="mt-2">
                    <span class="me-3"><strong><?= format_number($s['offer_count']) ?></strong> active offers</span>
                    <span class="text-muted">Last: <?= time_ago($s['last_activity']) ?></span>
                </div>
                <div class="mt-2">
                    <button class="btn btn-outline-primary btn-sm" onclick="runJob(<?= $s['id'] ?>, 'scan')"><i class="bi bi-search"></i> Scan</button>
                    <button class="btn btn-outline-success btn-sm" onclick="runJob(<?= $s['id'] ?>, 'parse')"><i class="bi bi-download"></i> Parse</button>
                    <button class="btn btn-outline-warning btn-sm" onclick="runJob(<?= $s['id'] ?>, 'prices')"><i class="bi bi-currency-exchange"></i> Prices</button>
                    <button class="btn btn-outline-info btn-sm" onclick="runJob(<?= $s['id'] ?>, 'match')"><i class="bi bi-link-45deg"></i> Match</button>
                    <a href="<?= url('supplier_edit', ['id' => $s['id']]) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i></a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<h5>Recent Jobs</h5>
<table class="table table-sm table-hover">
    <thead><tr><th>#</th><th>Supplier</th><th>Type</th><th>Status</th><th>Started</th><th>Duration</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recentJobs as $job): ?>
        <tr>
            <td><?= $job['id'] ?></td>
            <td><?= esc($job['supplier_code'] ?? '—') ?></td>
            <td><?= esc($job['job_type']) ?></td>
            <td><?= badge($job['status']) ?></td>
            <td><?= time_ago($job['started_at']) ?></td>
            <td><?php
                if ($job['finished_at']) {
                    echo (strtotime($job['finished_at']) - strtotime($job['started_at'])) . 's';
                } elseif ($job['status'] === 'running') {
                    echo (time() - strtotime($job['started_at'])) . 's...';
                }
            ?></td>
            <td>
                <?php if ($job['status'] === 'running'): ?>
                    <button class="btn btn-outline-danger btn-xs" onclick="stopJob(<?= $job['id'] ?>)">Stop</button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
