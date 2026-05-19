<?php
$perPage = 30;
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;

$total = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM (
        SELECT catalog_product_id FROM supplier_offers
        WHERE catalog_product_id IS NOT NULL AND is_active = 1 AND price_purchase IS NOT NULL
        GROUP BY catalog_product_id HAVING COUNT(DISTINCT supplier_id) > 1
    ) t"
);

$products = $db->fetchAll(
    "SELECT cp.id, cp.name, cp.brand, cp.model,
        COUNT(DISTINCT so.supplier_id) as supplier_count,
        MIN(so.price_purchase) as min_price,
        MAX(so.price_purchase) as max_price,
        GROUP_CONCAT(DISTINCT s.code ORDER BY so.price_purchase) as suppliers_by_price
     FROM catalog_products cp
     JOIN supplier_offers so ON so.catalog_product_id = cp.id AND so.is_active = 1 AND so.price_purchase IS NOT NULL
     JOIN suppliers s ON s.id = so.supplier_id
     GROUP BY cp.id HAVING COUNT(DISTINCT so.supplier_id) > 1
     ORDER BY (MAX(so.price_purchase) - MIN(so.price_purchase)) DESC
     LIMIT {$perPage} OFFSET {$offset}"
);

$singleSupplier = $db->fetchAll(
    "SELECT cp.id, cp.name, cp.brand,
        so.price_purchase, so.price_regular, so.currency, s.code AS supplier_code
     FROM catalog_products cp
     JOIN supplier_offers so ON so.catalog_product_id = cp.id AND so.is_active = 1 AND so.price_purchase IS NOT NULL
     JOIN suppliers s ON s.id = so.supplier_id
     WHERE cp.id NOT IN (
        SELECT catalog_product_id FROM supplier_offers
        WHERE catalog_product_id IS NOT NULL AND is_active = 1
        GROUP BY catalog_product_id HAVING COUNT(DISTINCT supplier_id) > 1
     )
     ORDER BY so.price_purchase DESC LIMIT 20"
);
?>

<h4 class="mb-3"><?= t('price_comparison') ?> <small class="text-muted">(<?= format_number($total) ?> <?= t('multi_supplier') ?>)</small></h4>

<?php if (empty($products) && empty($singleSupplier)): ?>
    <div class="alert alert-info">No products with multiple supplier offers found yet. Run matching first.</div>
<?php endif; ?>

<?php if (!empty($products)): ?>
<table class="table table-sm table-hover">
    <thead><tr><th><?= t('product') ?></th><th><?= t('brand') ?></th><th><?= t('suppliers') ?></th><th><?= t('min_price') ?></th><th><?= t('max_price') ?></th><th><?= t('savings') ?></th><th><?= t('best_supplier') ?></th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
        <?php $savings = (float)$p['max_price'] - (float)$p['min_price']; $supplierList = explode(',', $p['suppliers_by_price']); ?>
        <tr style="cursor:pointer" onclick="location='<?= url('product', ['id' => $p['id']]) ?>'">
            <td><?= esc(mb_substr($p['name'], 0, 50)) ?></td>
            <td><?= esc($p['brand'] ?? '') ?></td>
            <td><?= $p['supplier_count'] ?></td>
            <td class="text-success"><strong><?= number_format((float)$p['min_price'], 2) ?></strong></td>
            <td><?= number_format((float)$p['max_price'], 2) ?></td>
            <td class="text-danger"><?= number_format($savings, 2) ?></td>
            <td><span class="badge bg-success"><?= esc($supplierList[0] ?? '') ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?= pagination($total, $perPage, $currentPage, url('prices')) ?>
<?php endif; ?>

<?php if (!empty($singleSupplier)): ?>
<h5 class="mt-4"><?= t('single_supplier') ?></h5>
<table class="table table-sm">
    <thead><tr><th><?= t('product') ?></th><th><?= t('brand') ?></th><th><?= t('suppliers') ?></th><th><?= t('purchase_price') ?></th><th><?= t('currency') ?></th></tr></thead>
    <tbody>
    <?php foreach ($singleSupplier as $p): ?>
        <tr style="cursor:pointer" onclick="location='<?= url('product', ['id' => $p['id']]) ?>'">
            <td><?= esc(mb_substr($p['name'], 0, 50)) ?></td>
            <td><?= esc($p['brand'] ?? '') ?></td>
            <td><span class="badge bg-secondary"><?= esc($p['supplier_code']) ?></span></td>
            <td><?= number_format((float)$p['price_purchase'], 2) ?></td>
            <td><?= esc($p['currency']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
