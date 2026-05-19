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
    "SELECT h.*, so.name AS offer_name, s.code AS supplier_code
     FROM supplier_offer_price_history h
     JOIN supplier_offers so ON so.id = h.supplier_offer_id
     JOIN suppliers s ON s.id = so.supplier_id
     WHERE so.catalog_product_id = ?
     ORDER BY h.checked_at DESC LIMIT 50",
    [$id]
);
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= url('catalog') ?>">Catalog</a></li>
        <li class="breadcrumb-item active">#<?= $product['id'] ?></li>
    </ol>
</nav>

<div class="row mb-4">
    <div class="col-md-8">
        <h4><?= esc($product['name']) ?></h4>
        <table class="table table-sm" style="max-width: 400px;">
            <tr><td class="text-muted">Brand</td><td><strong><?= esc($product['brand'] ?? '—') ?></strong></td></tr>
            <tr><td class="text-muted">Model</td><td><code><?= esc($product['model'] ?? '—') ?></code></td></tr>
            <tr><td class="text-muted">SKU</td><td><?= esc($product['sku'] ?? '—') ?></td></tr>
            <tr><td class="text-muted">EAN</td><td><?= esc($product['ean'] ?? '—') ?></td></tr>
            <tr><td class="text-muted">Bundle</td><td><?= $product['is_bundle'] ? '<span class="badge bg-info">Yes</span>' : 'No' ?></td></tr>
        </table>
    </div>
    <div class="col-md-4">
        <?php
        $firstOffer = $offers[0] ?? null;
        if ($firstOffer) {
            $raw = json_decode($firstOffer['raw_data_json'] ?? '{}', true);
            $imgs = $raw['images'] ?? [];
            if (!empty($imgs[0])): ?>
                <img src="<?= esc($imgs[0]) ?>" class="img-fluid rounded" style="max-height: 200px;" alt="">
            <?php endif;
        } ?>
    </div>
</div>

<h5>Supplier Offers (<?= count($offers) ?>)</h5>
<table class="table table-sm table-hover">
    <thead><tr><th>Supplier</th><th>Price</th><th>Regular</th><th>Currency</th><th>Availability</th><th>Stock</th><th>Last Seen</th><th>Active</th><th>URL</th></tr></thead>
    <tbody>
    <?php foreach ($offers as $i => $o): ?>
        <tr class="<?= $i === 0 && count($offers) > 1 ? 'table-success' : '' ?>">
            <td><strong><?= esc($o['supplier_code']) ?></strong></td>
            <td><strong><?= $o['price_purchase'] !== null ? number_format((float)$o['price_purchase'], 2) : '—' ?></strong></td>
            <td><?= $o['price_regular'] !== null ? number_format((float)$o['price_regular'], 2) : '—' ?></td>
            <td><?= esc($o['currency']) ?></td>
            <td><?= badge($o['availability'] ?? 'unknown') ?></td>
            <td><small><?= esc($o['stock_qty_text'] ?? '') ?></small></td>
            <td><?= time_ago($o['last_seen_at']) ?></td>
            <td><?= $o['is_active'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' ?></td>
            <td><?php if ($o['url']): ?><a href="<?= esc($o['url']) ?>" target="_blank" class="btn btn-outline-primary btn-xs">Open</a><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if (!empty($history)): ?>
<h5 class="mt-4">Price History</h5>
<table class="table table-sm">
    <thead><tr><th>Date</th><th>Supplier</th><th>Purchase</th><th>Regular</th><th>Availability</th><th>Active</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
        <tr>
            <td><?= esc($h['checked_at']) ?></td>
            <td><code><?= esc($h['supplier_code']) ?></code></td>
            <td><?= $h['price_purchase'] !== null ? number_format((float)$h['price_purchase'], 2) : '—' ?></td>
            <td><?= $h['price_regular'] !== null ? number_format((float)$h['price_regular'], 2) : '—' ?></td>
            <td><?= esc($h['availability'] ?? '') ?></td>
            <td><?= $h['is_active'] ? 'Yes' : 'No' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
