<?php
// Supplier health
$suppliers = $db->fetchAll(
    "SELECT s.*,
        (SELECT COUNT(*) FROM supplier_offers WHERE supplier_id=s.id AND is_active=1) as active_offers,
        (SELECT COUNT(*) FROM supplier_offers WHERE supplier_id=s.id) as total_offers,
        (SELECT COUNT(*) FROM supplier_offers WHERE supplier_id=s.id AND is_active=0) as inactive_offers,
        (SELECT MAX(last_seen_at) FROM supplier_offers WHERE supplier_id=s.id) as last_activity,
        (SELECT MIN(last_price_check_at) FROM supplier_offers WHERE supplier_id=s.id AND is_active=1) as oldest_price_check,
        (SELECT AVG(price_purchase) FROM supplier_offers WHERE supplier_id=s.id AND is_active=1 AND price_purchase>0) as avg_price
     FROM suppliers s ORDER BY s.id"
);

// Queue health
$queueHealth = $db->fetchAll(
    "SELECT s.code,
        SUM(CASE WHEN pq.status='done' THEN 1 ELSE 0 END) as done,
        SUM(CASE WHEN pq.status='error' THEN 1 ELSE 0 END) as errors,
        SUM(CASE WHEN pq.status='new' THEN 1 ELSE 0 END) as pending,
        COUNT(*) as total
     FROM parse_queue pq
     JOIN suppliers s ON s.id = pq.supplier_id
     GROUP BY s.code"
);

// Price changes in last 7 days
$priceChanges = $db->fetchAll(
    "SELECT DATE(h.checked_at) as day, s.code, COUNT(*) as changes,
        SUM(CASE WHEN h.price_purchase < prev.price_purchase THEN 1 ELSE 0 END) as drops,
        SUM(CASE WHEN h.price_purchase > prev.price_purchase THEN 1 ELSE 0 END) as increases
     FROM supplier_offer_price_history h
     JOIN supplier_offers so ON so.id = h.supplier_offer_id
     JOIN suppliers s ON s.id = so.supplier_id
     LEFT JOIN supplier_offer_price_history prev ON prev.supplier_offer_id = h.supplier_offer_id
        AND prev.checked_at = (SELECT MAX(checked_at) FROM supplier_offer_price_history WHERE supplier_offer_id = h.supplier_offer_id AND checked_at < h.checked_at)
     WHERE h.checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY day, s.code
     ORDER BY day DESC"
);

// Top price drops
$topDrops = $db->fetchAll(
    "SELECT h.*, so.name, so.url, s.code AS supplier_code,
        (SELECT price_purchase FROM supplier_offer_price_history
         WHERE supplier_offer_id = h.supplier_offer_id AND checked_at < h.checked_at
         ORDER BY checked_at DESC LIMIT 1) as prev_price
     FROM supplier_offer_price_history h
     JOIN supplier_offers so ON so.id = h.supplier_offer_id
     JOIN suppliers s ON s.id = so.supplier_id
     WHERE h.checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     HAVING prev_price IS NOT NULL AND prev_price > h.price_purchase
     ORDER BY (prev_price - h.price_purchase) DESC
     LIMIT 20"
);

// Category distribution
$categoryDist = $db->fetchAll(
    "SELECT c.name, COUNT(DISTINCT pc.product_id) as product_count
     FROM product_categories pc
     JOIN categories c ON c.id = pc.category_id
     GROUP BY c.id ORDER BY product_count DESC LIMIT 15"
);

// Brand distribution
$brandDist = $db->fetchAll(
    "SELECT brand, COUNT(*) as cnt FROM catalog_products WHERE brand IS NOT NULL AND brand != '' GROUP BY brand ORDER BY cnt DESC LIMIT 15"
);

// Matching coverage
$totalOffers = (int)$db->fetchColumn("SELECT COUNT(*) FROM supplier_offers WHERE is_active = 1");
$matchedOffers = (int)$db->fetchColumn("SELECT COUNT(*) FROM supplier_offers WHERE is_active = 1 AND catalog_product_id IS NOT NULL");
$unmatchedOffers = $totalOffers - $matchedOffers;
$matchPct = $totalOffers > 0 ? round($matchedOffers / $totalOffers * 100, 1) : 0;

// Recent job performance
$jobPerf = $db->fetchAll(
    "SELECT job_type, status, COUNT(*) as cnt, AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)) as avg_duration
     FROM job_runs
     WHERE started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY job_type, status"
);
?>

<h4 class="mb-4"><i class="bi bi-bar-chart-line"></i> Analytics</h4>

<!-- Supplier Health Cards -->
<div class="row g-3 mb-4">
    <?php foreach ($suppliers as $s): ?>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6><?= esc($s['name']) ?> <?= badge($s['type']) ?></h6>
                <div class="row text-center mt-2">
                    <div class="col-4">
                        <div class="fs-5 fw-bold text-success"><?= format_number($s['active_offers']) ?></div>
                        <small class="text-muted">Active</small>
                    </div>
                    <div class="col-4">
                        <div class="fs-5 fw-bold text-danger"><?= format_number($s['inactive_offers']) ?></div>
                        <small class="text-muted">Inactive</small>
                    </div>
                    <div class="col-4">
                        <div class="fs-5 fw-bold text-primary"><?= $s['avg_price'] ? number_format((float)$s['avg_price'], 0) : '—' ?></div>
                        <small class="text-muted">Avg Price</small>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted">Last activity: <?= time_ago($s['last_activity']) ?></small><br>
                    <small class="text-muted">Oldest check: <?= time_ago($s['oldest_price_check']) ?></small>
                </div>
                <?php
                $health = 'success';
                if ($s['last_activity'] && strtotime($s['last_activity']) < strtotime('-3 days')) $health = 'warning';
                if ($s['last_activity'] && strtotime($s['last_activity']) < strtotime('-7 days')) $health = 'danger';
                if (!$s['last_activity']) $health = 'secondary';
                ?>
                <div class="mt-2"><span class="badge bg-<?= $health ?>"><?= $health === 'success' ? 'Healthy' : ($health === 'warning' ? 'Stale' : ($health === 'danger' ? 'Critical' : 'No data')) ?></span></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <!-- Matching Coverage -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Matching Coverage</div>
            <div class="card-body text-center">
                <div class="fs-2 fw-bold <?= $matchPct >= 80 ? 'text-success' : ($matchPct >= 50 ? 'text-warning' : 'text-danger') ?>"><?= $matchPct ?>%</div>
                <div class="progress mb-2" style="height:8px">
                    <div class="progress-bar bg-success" style="width:<?= $matchPct ?>%"></div>
                </div>
                <small class="text-muted"><?= format_number($matchedOffers) ?> matched / <?= format_number($unmatchedOffers) ?> unmatched</small>
            </div>
        </div>
    </div>

    <!-- Queue Success Rate -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Queue Success Rate</div>
            <div class="card-body">
                <?php foreach ($queueHealth as $q): $rate = $q['total'] > 0 ? round($q['done'] / $q['total'] * 100, 1) : 0; ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between"><small><strong><?= esc($q['code']) ?></strong></small><small><?= $rate ?>%</small></div>
                    <div class="progress" style="height:6px">
                        <div class="progress-bar bg-success" style="width:<?= $rate ?>%"></div>
                        <div class="progress-bar bg-danger" style="width:<?= $q['total'] > 0 ? round($q['errors'] / $q['total'] * 100, 1) : 0 ?>%"></div>
                    </div>
                    <small class="text-muted"><?= format_number($q['done']) ?> done, <?= format_number($q['errors']) ?> errors, <?= format_number($q['pending']) ?> pending</small>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Job Performance -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Job Performance (7d)</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Job</th><th>Runs</th><th>Avg Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($jobPerf as $j): ?>
                        <tr>
                            <td><code><?= esc($j['job_type']) ?></code> <?= badge($j['status']) ?></td>
                            <td><?= $j['cnt'] ?></td>
                            <td><?= $j['avg_duration'] ? round((float)$j['avg_duration']) . 's' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Brand Distribution -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Top Brands</div>
            <div class="card-body" style="height:300px">
                <canvas id="brandChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Category Distribution -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Top Categories</div>
            <div class="card-body" style="height:300px">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Top Price Drops -->
<?php if (!empty($topDrops)): ?>
<div class="card mb-4">
    <div class="card-header"><strong><i class="bi bi-arrow-down-circle text-success"></i> Top Price Drops (7 days)</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th>Product</th><th>Supplier</th><th>Was</th><th>Now</th><th>Drop</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($topDrops as $d):
                $drop = (float)$d['prev_price'] - (float)$d['price_purchase'];
                $pct = (float)$d['prev_price'] > 0 ? ($drop / (float)$d['prev_price']) * 100 : 0;
            ?>
                <tr>
                    <td><?= esc(mb_substr($d['name'], 0, 50)) ?></td>
                    <td><code><?= esc($d['supplier_code']) ?></code></td>
                    <td class="text-muted"><?= number_format((float)$d['prev_price'], 2) ?></td>
                    <td class="text-success fw-bold"><?= number_format((float)$d['price_purchase'], 2) ?></td>
                    <td><span class="text-success">-<?= number_format($drop, 2) ?> (<?= round($pct, 1) ?>%)</span></td>
                    <td><small><?= esc($d['checked_at']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('brandChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($brandDist, 'brand')) ?>,
        datasets: [{label: 'Products', data: <?= json_encode(array_column($brandDist, 'cnt')) ?>, backgroundColor: '#0d6efd44', borderColor: '#0d6efd', borderWidth: 1}]
    },
    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($c) => mb_substr($c['name'], 0, 25), $categoryDist)) ?>,
        datasets: [{label: 'Products', data: <?= json_encode(array_column($categoryDist, 'product_count')) ?>, backgroundColor: '#19875444', borderColor: '#198754', borderWidth: 1}]
    },
    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } } }
});
</script>
