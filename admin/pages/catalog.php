<?php
$search = trim($_GET['q'] ?? '');
$brand = trim($_GET['brand'] ?? '');
$perPage = 30;
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;

$where = [];
$params = [];
if ($search) {
    $where[] = '(cp.name LIKE ? OR cp.sku LIKE ? OR cp.ean LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($brand) {
    $where[] = 'cp.brand = ?';
    $params[] = $brand;
}
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->fetchColumn("SELECT COUNT(*) FROM catalog_products cp {$whereStr}", $params);
$products = $db->fetchAll(
    "SELECT cp.*,
        (SELECT COUNT(*) FROM supplier_offers WHERE catalog_product_id=cp.id AND is_active=1) as offer_count,
        (SELECT GROUP_CONCAT(DISTINCT s.code) FROM supplier_offers so JOIN suppliers s ON s.id=so.supplier_id WHERE so.catalog_product_id=cp.id AND so.is_active=1) as supplier_codes
     FROM catalog_products cp {$whereStr}
     ORDER BY cp.id DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$brands = $db->fetchAll("SELECT DISTINCT brand FROM catalog_products WHERE brand IS NOT NULL AND brand != '' ORDER BY brand LIMIT 100");
?>

<h4 class="mb-3"><?= t('catalog') ?> <small class="text-muted">(<?= format_number($total) ?>)</small></h4>

<form class="row g-2 mb-3">
    <input type="hidden" name="page" value="catalog">
    <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="<?= t('search_placeholder') ?>" value="<?= esc($search) ?>">
    </div>
    <div class="col-md-3">
        <select name="brand" class="form-select form-select-sm">
            <option value=""><?= t('all_brands') ?></option>
            <?php foreach ($brands as $b): ?>
                <option value="<?= esc($b['brand']) ?>" <?= $brand === $b['brand'] ? 'selected' : '' ?>><?= esc($b['brand']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('search') ?></button></div>
</form>

<table class="table table-sm table-hover">
    <thead><tr><th>ID</th><th><?= t('supplier_name') ?></th><th><?= t('brand') ?></th><th><?= t('model') ?></th><th>SKU</th><th>EAN</th><th><?= t('offers_from') ?></th><th><?= t('suppliers') ?></th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
        <tr style="cursor:pointer" onclick="location='<?= url('product', ['id' => $p['id']]) ?>'">
            <td><?= $p['id'] ?></td>
            <td><?= esc(mb_substr($p['name'], 0, 60)) ?></td>
            <td><?= esc($p['brand'] ?? '') ?></td>
            <td><code><?= esc($p['model'] ?? '') ?></code></td>
            <td><small><?= esc($p['sku'] ?? '') ?></small></td>
            <td><small><?= esc($p['ean'] ?? '') ?></small></td>
            <td><span class="badge bg-primary"><?= $p['offer_count'] ?></span></td>
            <td>
                <?php foreach (explode(',', $p['supplier_codes'] ?? '') as $sc): ?>
                    <?php if (trim($sc)): ?><span class="badge bg-secondary"><?= esc(trim($sc)) ?></span><?php endif; ?>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= pagination($total, $perPage, $currentPage, url('catalog', array_filter(['q' => $search, 'brand' => $brand]))) ?>
