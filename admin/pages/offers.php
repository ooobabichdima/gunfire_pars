<?php
$search = trim($_GET['q'] ?? '');
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$active = $_GET['active'] ?? '';
$perPage = 30;
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;

$where = [];
$params = [];
if ($search) {
    $where[] = '(so.name LIKE ? OR so.external_sku LIKE ? OR so.ean LIKE ? OR so.brand LIKE ?)';
    $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"]);
}
if ($supplierId > 0) { $where[] = 'so.supplier_id = ?'; $params[] = $supplierId; }
if ($active === '1') { $where[] = 'so.is_active = 1'; }
if ($active === '0') { $where[] = 'so.is_active = 0'; }
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->fetchColumn("SELECT COUNT(*) FROM supplier_offers so {$whereStr}", $params);

$offers = $db->fetchAll(
    "SELECT so.*, s.code AS supplier_code, s.name AS supplier_name,
        cp.name AS catalog_name, cp.brand AS catalog_brand
     FROM supplier_offers so
     JOIN suppliers s ON s.id = so.supplier_id
     LEFT JOIN catalog_products cp ON cp.id = so.catalog_product_id
     {$whereStr} ORDER BY so.id DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$suppliers = $db->fetchAll("SELECT id, code, name FROM suppliers ORDER BY code");

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=offers_export_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Supplier', 'Name', 'Brand', 'SKU', 'EAN', 'Purchase Price', 'Regular Price', 'Currency', 'Availability', 'Stock', 'Active', 'URL', 'Last Seen']);
    $allOffers = $db->fetchAll(
        "SELECT so.*, s.code AS supplier_code FROM supplier_offers so JOIN suppliers s ON s.id=so.supplier_id {$whereStr} ORDER BY so.id",
        $params
    );
    foreach ($allOffers as $o) {
        fputcsv($out, [$o['id'], $o['supplier_code'], $o['name'], $o['brand'], $o['external_sku'], $o['ean'],
            $o['price_purchase'], $o['price_regular'], $o['currency'], $o['availability'], $o['stock_qty_text'],
            $o['is_active'], $o['url'], $o['last_seen_at']]);
    }
    fclose($out);
    exit;
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">All Offers <small class="text-muted">(<?= format_number($total) ?>)</small></h4>
    <a href="<?= url('offers', array_merge(array_filter(['q' => $search, 'supplier_id' => $supplierId, 'active' => $active]), ['export' => 'csv'])) ?>"
       class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i> Export CSV</a>
</div>

<form class="row g-2 mb-3">
    <input type="hidden" name="page" value="offers">
    <div class="col-md-3">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, SKU, EAN, brand..." value="<?= esc($search) ?>">
    </div>
    <div class="col-md-2">
        <select name="supplier_id" class="form-select form-select-sm">
            <option value="">All suppliers</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $supplierId == $s['id'] ? 'selected' : '' ?>><?= esc($s['code']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="active" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="1" <?= $active === '1' ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= $active === '0' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Search</button></div>
</form>

<div class="table-responsive">
    <table class="table table-sm table-hover">
        <thead><tr><th>ID</th><th>Supplier</th><th>Name</th><th>Brand</th><th>SKU</th><th>Price</th><th>Currency</th><th>Availability</th><th>Matched</th><th>Active</th><th>Last Seen</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($offers as $o): ?>
            <tr>
                <td><?= $o['id'] ?></td>
                <td><code><?= esc($o['supplier_code']) ?></code></td>
                <td title="<?= esc($o['name']) ?>"><?= esc(mb_substr($o['name'], 0, 45)) ?></td>
                <td><small><?= esc($o['brand'] ?? '') ?></small></td>
                <td><small><code><?= esc($o['external_sku'] ?? '') ?></code></small></td>
                <td><strong><?= $o['price_purchase'] !== null ? number_format((float)$o['price_purchase'], 2) : '—' ?></strong></td>
                <td><?= esc($o['currency']) ?></td>
                <td><?= badge($o['availability'] ?? 'unknown') ?></td>
                <td>
                    <?php if ($o['catalog_product_id']): ?>
                        <a href="<?= url('product', ['id' => $o['catalog_product_id']]) ?>" class="badge bg-success text-decoration-none">#<?= $o['catalog_product_id'] ?></a>
                    <?php else: ?>
                        <span class="badge bg-secondary">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $o['is_active'] ? '<i class="bi bi-check text-success"></i>' : '<i class="bi bi-x text-danger"></i>' ?></td>
                <td><small><?= time_ago($o['last_seen_at']) ?></small></td>
                <td><?php if ($o['url']): ?><a href="<?= esc($o['url']) ?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= pagination($total, $perPage, $currentPage, url('offers', array_filter(['q' => $search, 'supplier_id' => $supplierId, 'active' => $active]))) ?>
