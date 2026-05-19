<?php
$id = (int)($_GET['id'] ?? 0);
$product = $db->fetchOne('SELECT * FROM catalog_products WHERE id = ?', [$id]);
if (!$product) { echo '<div class="alert alert-warning">Product not found</div>'; return; }

$offers = $db->fetchAll(
    "SELECT so.*, s.code AS supplier_code, s.name AS supplier_name
     FROM supplier_offers so
     JOIN suppliers s ON s.id = so.supplier_id
     WHERE so.catalog_product_id = ?
     ORDER BY so.price_purchase ASC",
    [$id]
);

$history = $db->fetchAll(
    "SELECT h.*, so.name AS offer_name, s.code AS supplier_code, s.name AS supplier_name
     FROM supplier_offer_price_history h
     JOIN supplier_offers so ON so.id = h.supplier_offer_id
     JOIN suppliers s ON s.id = so.supplier_id
     WHERE so.catalog_product_id = ?
     ORDER BY h.checked_at ASC",
    [$id]
);

// Collect all images from all offers
$allImages = [];
foreach ($offers as $o) {
    $raw = json_decode($o['raw_data_json'] ?? '{}', true);
    $imgs = $raw['images'] ?? [];
    foreach ($imgs as $img) {
        $allImages[$img] = $o['supplier_code'];
    }
}

// Collect specs from all offers
$allSpecs = [];
foreach ($offers as $o) {
    $raw = json_decode($o['raw_data_json'] ?? '{}', true);
    $specs = $raw['specifications'] ?? [];
    if (!empty($specs)) {
        $allSpecs[$o['supplier_code']] = $specs;
    }
}

// Build chart data — group by supplier
$chartData = [];
foreach ($history as $h) {
    $code = $h['supplier_code'];
    if (!isset($chartData[$code])) {
        $chartData[$code] = ['labels' => [], 'prices' => [], 'regular' => []];
    }
    $chartData[$code]['labels'][] = $h['checked_at'];
    $chartData[$code]['prices'][] = (float)$h['price_purchase'];
    $chartData[$code]['regular'][] = (float)$h['price_regular'];
}

$chartColors = ['#0d6efd', '#198754', '#dc3545', '#ffc107', '#6f42c1', '#0dcaf0', '#fd7e14', '#20c997'];
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= url('catalog') ?>"><?= t('catalog') ?></a></li>
        <li class="breadcrumb-item active">#<?= $product['id'] ?> <?= esc(mb_substr($product['name'], 0, 50)) ?></li>
    </ol>
</nav>

<div class="row mb-4">
    <div class="col-md-7">
        <h4><?= esc($product['name']) ?></h4>
        <div class="row mt-3">
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr><td class="text-muted" style="width:100px"><?= t('brand') ?></td><td><strong><?= esc($product['brand'] ?? '—') ?></strong></td></tr>
                    <tr><td class="text-muted"><?= t('model') ?></td><td><code><?= esc($product['model'] ?? '—') ?></code></td></tr>
                    <tr><td class="text-muted">SKU</td><td><?= esc($product['sku'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted">EAN</td><td><?= esc($product['ean'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted"><?= t('bundle') ?></td><td><?= $product['is_bundle'] ? '<span class="badge bg-info">' . t('yes') . '</span>' : t('no') ?></td></tr>
                    <tr><td class="text-muted"><?= t('supplier_offers') ?></td><td><strong><?= count($offers) ?></strong> <?= t('offers_from') ?> <?= count(array_unique(array_column($offers, 'supplier_code'))) ?> <?= t('suppliers') ?></td></tr>
                </table>
            </div>
            <?php if (!empty($allSpecs)): ?>
            <div class="col-md-6">
                <?php $firstSpecs = reset($allSpecs); ?>
                <table class="table table-sm">
                    <?php $si = 0; foreach ($firstSpecs as $k => $v): if ($si++ > 8) break; ?>
                        <tr><td class="text-muted" style="width:45%"><?= esc($k) ?></td><td><?= esc($v) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Image Gallery -->
    <div class="col-md-5">
        <?php if (!empty($allImages)): ?>
        <div id="gallery-main" class="mb-2 text-center bg-light rounded p-2" style="height:280px;display:flex;align-items:center;justify-content:center;">
            <img id="gallery-img" src="<?= esc(array_key_first($allImages)) ?>" class="img-fluid rounded" style="max-height:270px;cursor:pointer;" onclick="window.open(this.src,'_blank')">
        </div>
        <div class="d-flex flex-wrap gap-1">
            <?php foreach ($allImages as $imgUrl => $supCode): ?>
                <img src="<?= esc($imgUrl) ?>" class="rounded border" style="width:54px;height:54px;object-fit:cover;cursor:pointer;"
                     onclick="document.getElementById('gallery-img').src=this.src"
                     title="<?= esc($supCode) ?>">
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="bg-light rounded d-flex align-items-center justify-content-center" style="height:200px">
                <span class="text-muted"><?= t('no_images') ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Price History Chart -->
<?php if (!empty($chartData)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-graph-up"></i> <?= t('price_history') ?></strong>
        <div>
            <?php $ci = 0; foreach ($chartData as $code => $d): ?>
                <span class="badge" style="background:<?= $chartColors[$ci % count($chartColors)] ?>"><?= esc($code) ?></span>
            <?php $ci++; endforeach; ?>
        </div>
    </div>
    <div class="card-body" style="height:320px">
        <canvas id="priceChart"></canvas>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
(function(){
    const datasets = [];
    <?php $ci = 0; foreach ($chartData as $code => $d): ?>
    datasets.push({
        label: '<?= esc($code) ?> (purchase)',
        data: <?= json_encode(array_map(fn($l, $p) => ['x' => $l, 'y' => $p], $d['labels'], $d['prices'])) ?>,
        borderColor: '<?= $chartColors[$ci % count($chartColors)] ?>',
        backgroundColor: '<?= $chartColors[$ci % count($chartColors)] ?>22',
        fill: false,
        tension: 0.3,
        pointRadius: 3,
    });
    datasets.push({
        label: '<?= esc($code) ?> (regular)',
        data: <?= json_encode(array_map(fn($l, $p) => ['x' => $l, 'y' => $p], $d['labels'], $d['regular'])) ?>,
        borderColor: '<?= $chartColors[$ci % count($chartColors)] ?>88',
        borderDash: [5,5],
        fill: false,
        tension: 0.3,
        pointRadius: 0,
    });
    <?php $ci++; endforeach; ?>

    new Chart(document.getElementById('priceChart'), {
        type: 'line',
        data: { datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', intersect: false },
            scales: {
                x: { type: 'category', title: { display: true, text: 'Date' }, ticks: { maxTicksLimit: 15, maxRotation: 45 } },
                y: { title: { display: true, text: 'Price' }, beginAtZero: false }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2)
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<!-- Supplier Offers Table -->
<div class="card mb-4">
    <div class="card-header"><strong><?= t('supplier_offers') ?> (<?= count($offers) ?>)</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr>
                <th></th><th><?= t('suppliers') ?></th><th><?= t('purchase_price') ?></th><th><?= t('regular_price') ?></th><th><?= t('currency') ?></th>
                <th><?= t('availability') ?></th><th><?= t('stock') ?></th><th><?= t('last_seen') ?></th><th><?= t('last_price_check') ?></th><th><?= t('active') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($offers as $i => $o): ?>
                <tr class="<?= $i === 0 && count($offers) > 1 ? 'table-success' : '' ?>">
                    <td><?= $i === 0 && count($offers) > 1 ? '<i class="bi bi-trophy-fill text-warning"></i>' : '' ?></td>
                    <td><strong><?= esc($o['supplier_name']) ?></strong> <small class="text-muted"><?= esc($o['supplier_code']) ?></small></td>
                    <td><strong><?= $o['price_purchase'] !== null ? number_format((float)$o['price_purchase'], 2) : '—' ?></strong></td>
                    <td class="text-muted"><?= $o['price_regular'] !== null ? number_format((float)$o['price_regular'], 2) : '—' ?></td>
                    <td><?= esc($o['currency']) ?></td>
                    <td><?= badge($o['availability'] ?? 'unknown') ?></td>
                    <td><small><?= esc($o['stock_qty_text'] ?? '') ?></small></td>
                    <td><?= time_ago($o['last_seen_at']) ?></td>
                    <td><?= time_ago($o['last_price_check_at']) ?></td>
                    <td><?= $o['is_active'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' ?></td>
                    <td><?php if ($o['url']): ?><a href="<?= esc($o['url']) ?>" target="_blank" class="btn btn-outline-primary btn-xs"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Price History Table -->
<?php if (!empty($history)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between">
        <strong><?= t('price_history') ?> (<?= count($history) ?>)</strong>
        <a href="<?= url('price_history', ['product_id' => $id, 'export' => 'csv']) ?>" class="btn btn-outline-success btn-xs"><i class="bi bi-download"></i> CSV</a>
    </div>
    <div class="table-responsive" style="max-height:400px;overflow-y:auto">
        <table class="table table-sm mb-0">
            <thead class="sticky-top bg-white"><tr><th><?= t('date') ?></th><th><?= t('suppliers') ?></th><th><?= t('purchase_price') ?></th><th><?= t('regular_price') ?></th><th><?= t('change') ?></th><th><?= t('availability') ?></th><th><?= t('active') ?></th></tr></thead>
            <tbody>
            <?php
            $prevPrices = [];
            foreach (array_reverse($history) as $h):
                $code = $h['supplier_code'];
                $price = (float)$h['price_purchase'];
                $change = '';
                if (isset($prevPrices[$code]) && $prevPrices[$code] != $price) {
                    $diff = $price - $prevPrices[$code];
                    $pct = $prevPrices[$code] > 0 ? ($diff / $prevPrices[$code]) * 100 : 0;
                    $arrow = $diff < 0 ? '<i class="bi bi-arrow-down-circle text-success"></i>' : '<i class="bi bi-arrow-up-circle text-danger"></i>';
                    $change = $arrow . ' ' . sprintf('%+.2f (%.1f%%)', $diff, $pct);
                }
                $prevPrices[$code] = $price;
            ?>
                <tr>
                    <td><small><?= esc($h['checked_at']) ?></small></td>
                    <td><code><?= esc($code) ?></code></td>
                    <td><?= number_format($price, 2) ?></td>
                    <td class="text-muted"><?= $h['price_regular'] !== null ? number_format((float)$h['price_regular'], 2) : '—' ?></td>
                    <td><small><?= $change ?></small></td>
                    <td><?= badge($h['availability'] ?? 'unknown') ?></td>
                    <td><?= $h['is_active'] ? '<i class="bi bi-check text-success"></i>' : '<i class="bi bi-x text-danger"></i>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Full Specifications -->
<?php if (!empty($allSpecs)): ?>
<div class="card mb-4">
    <div class="card-header"><strong><?= t('specifications') ?></strong></div>
    <div class="card-body">
        <?php if (count($allSpecs) > 1): ?>
            <ul class="nav nav-tabs mb-3" role="tablist">
                <?php $ti = 0; foreach ($allSpecs as $code => $specs): ?>
                    <li class="nav-item"><a class="nav-link <?= $ti === 0 ? 'active' : '' ?>" data-bs-toggle="tab" href="#specs-<?= esc($code) ?>"><?= esc($code) ?></a></li>
                <?php $ti++; endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="tab-content">
            <?php $ti = 0; foreach ($allSpecs as $code => $specs): ?>
            <div class="tab-pane <?= $ti === 0 ? 'show active' : '' ?>" id="specs-<?= esc($code) ?>">
                <table class="table table-sm" style="max-width:600px">
                    <?php foreach ($specs as $k => $v): ?>
                        <tr><td class="text-muted" style="width:40%"><?= esc($k) ?></td><td><?= esc($v) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php $ti++; endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Description -->
<?php
$descriptions = [];
foreach ($offers as $o) {
    $raw = json_decode($o['raw_data_json'] ?? '{}', true);
    $desc = $raw['description'] ?? '';
    if (!empty($desc) && mb_strlen($desc) > 30) {
        $descriptions[$o['supplier_code']] = $desc;
    }
}
if (!empty($descriptions)): ?>
<div class="card mb-4">
    <div class="card-header"><strong><?= t('description') ?></strong></div>
    <div class="card-body">
        <?php if (count($descriptions) > 1): ?>
            <ul class="nav nav-tabs mb-3" role="tablist">
                <?php $di = 0; foreach ($descriptions as $code => $desc): ?>
                    <li class="nav-item"><a class="nav-link <?= $di === 0 ? 'active' : '' ?>" data-bs-toggle="tab" href="#desc-<?= esc($code) ?>"><?= esc($code) ?></a></li>
                <?php $di++; endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="tab-content">
            <?php $di = 0; foreach ($descriptions as $code => $desc): ?>
            <div class="tab-pane <?= $di === 0 ? 'show active' : '' ?>" id="desc-<?= esc($code) ?>">
                <p style="white-space:pre-line"><?= esc($desc) ?></p>
            </div>
            <?php $di++; endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
