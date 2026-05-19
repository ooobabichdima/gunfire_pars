<?php
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);
$days = (int)($_GET['days'] ?? 7);
$perPage = 50;
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;

$where = ['1=1'];
$params = [];
if ($supplierId > 0) { $where[] = 's.id = ?'; $params[] = $supplierId; }
if ($productId > 0) { $where[] = 'so.catalog_product_id = ?'; $params[] = $productId; }
$where[] = 'h.checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
$params[] = $days;
$whereStr = implode(' AND ', $where);

$total = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM supplier_offer_price_history h
     JOIN supplier_offers so ON so.id = h.supplier_offer_id
     JOIN suppliers s ON s.id = so.supplier_id WHERE {$whereStr}",
    $params
);

$rows = $db->fetchAll(
    "SELECT h.*, so.name, so.url, so.catalog_product_id, so.external_sku, s.code AS supplier_code,
        (SELECT price_purchase FROM supplier_offer_price_history
         WHERE supplier_offer_id = h.supplier_offer_id AND checked_at < h.checked_at
         ORDER BY checked_at DESC LIMIT 1) as prev_price
     FROM supplier_offer_price_history h
     JOIN supplier_offers so ON so.id = h.supplier_offer_id
     JOIN suppliers s ON s.id = so.supplier_id
     WHERE {$whereStr}
     ORDER BY h.checked_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$suppliers = $db->fetchAll("SELECT id, code FROM suppliers ORDER BY code");

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=price_history_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Supplier', 'Product', 'SKU', 'Prev Price', 'New Price', 'Change', 'Change %', 'Currency', 'Availability', 'Active']);
    $allRows = $db->fetchAll(
        "SELECT h.*, so.name, so.external_sku, s.code AS supplier_code,
            (SELECT price_purchase FROM supplier_offer_price_history
             WHERE supplier_offer_id = h.supplier_offer_id AND checked_at < h.checked_at
             ORDER BY checked_at DESC LIMIT 1) as prev_price
         FROM supplier_offer_price_history h
         JOIN supplier_offers so ON so.id = h.supplier_offer_id
         JOIN suppliers s ON s.id = so.supplier_id WHERE {$whereStr} ORDER BY h.checked_at DESC",
        $params
    );
    foreach ($allRows as $r) {
        $change = $r['prev_price'] !== null ? (float)$r['price_purchase'] - (float)$r['prev_price'] : 0;
        $pct = $r['prev_price'] && (float)$r['prev_price'] > 0 ? ($change / (float)$r['prev_price']) * 100 : 0;
        fputcsv($out, [$r['checked_at'], $r['supplier_code'], $r['name'], $r['external_sku'],
            $r['prev_price'], $r['price_purchase'], round($change, 2), round($pct, 1), $r['currency'], $r['availability'], $r['is_active']]);
    }
    fclose($out);
    exit;
}

// Summary stats
$summary = $db->fetchOne(
    "SELECT COUNT(*) as total_changes,
        SUM(CASE WHEN h.price_purchase < (SELECT price_purchase FROM supplier_offer_price_history WHERE supplier_offer_id=h.supplier_offer_id AND checked_at<h.checked_at ORDER BY checked_at DESC LIMIT 1) THEN 1 ELSE 0 END) as drops,
        SUM(CASE WHEN h.price_purchase > (SELECT price_purchase FROM supplier_offer_price_history WHERE supplier_offer_id=h.supplier_offer_id AND checked_at<h.checked_at ORDER BY checked_at DESC LIMIT 1) THEN 1 ELSE 0 END) as increases
     FROM supplier_offer_price_history h
     JOIN supplier_offers so ON so.id = h.supplier_offer_id
     JOIN suppliers s ON s.id = so.supplier_id WHERE {$whereStr}",
    $params
);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-clock-history"></i> Price History</h4>
    <a href="<?= url('price_history', array_merge(array_filter(['supplier_id' => $supplierId, 'product_id' => $productId, 'days' => $days]), ['export' => 'csv'])) ?>"
       class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i> Export CSV</a>
</div>

<div class="row g-2 mb-3">
    <div class="col-auto">
        <span class="badge bg-primary"><?= format_number($summary['total_changes'] ?? 0) ?> changes</span>
        <span class="badge bg-success"><i class="bi bi-arrow-down"></i> <?= format_number($summary['drops'] ?? 0) ?> drops</span>
        <span class="badge bg-danger"><i class="bi bi-arrow-up"></i> <?= format_number($summary['increases'] ?? 0) ?> increases</span>
    </div>
</div>

<form class="row g-2 mb-3">
    <input type="hidden" name="page" value="price_history">
    <div class="col-auto">
        <select name="supplier_id" class="form-select form-select-sm">
            <option value="">All suppliers</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $supplierId == $s['id'] ? 'selected' : '' ?>><?= esc($s['code']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <select name="days" class="form-select form-select-sm">
            <?php foreach ([1, 3, 7, 14, 30, 90] as $d): ?>
                <option value="<?= $d ?>" <?= $days == $d ? 'selected' : '' ?>><?= $d ?> days</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <input type="number" name="product_id" class="form-control form-control-sm" placeholder="Product ID" value="<?= $productId ?: '' ?>" style="width:120px">
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Filter</button></div>
</form>

<div class="table-responsive">
    <table class="table table-sm table-hover">
        <thead><tr><th>Date</th><th>Supplier</th><th>Product</th><th>SKU</th><th>Prev Price</th><th>New Price</th><th>Change</th><th>Availability</th><th>Active</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $hasPrev = $r['prev_price'] !== null;
            $change = $hasPrev ? (float)$r['price_purchase'] - (float)$r['prev_price'] : 0;
            $pct = $hasPrev && (float)$r['prev_price'] > 0 ? ($change / (float)$r['prev_price']) * 100 : 0;
        ?>
            <tr>
                <td><small><?= esc($r['checked_at']) ?></small></td>
                <td><code><?= esc($r['supplier_code']) ?></code></td>
                <td>
                    <?php if ($r['catalog_product_id']): ?>
                        <a href="<?= url('product', ['id' => $r['catalog_product_id']]) ?>"><?= esc(mb_substr($r['name'], 0, 40)) ?></a>
                    <?php else: ?>
                        <?= esc(mb_substr($r['name'], 0, 40)) ?>
                    <?php endif; ?>
                </td>
                <td><small><code><?= esc($r['external_sku'] ?? '') ?></code></small></td>
                <td class="text-muted"><?= $hasPrev ? number_format((float)$r['prev_price'], 2) : '—' ?></td>
                <td><strong><?= number_format((float)$r['price_purchase'], 2) ?></strong></td>
                <td>
                    <?php if ($hasPrev && abs($change) > 0.01): ?>
                        <span class="<?= $change < 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $change < 0 ? '<i class="bi bi-arrow-down-short"></i>' : '<i class="bi bi-arrow-up-short"></i>' ?>
                            <?= sprintf('%+.2f (%.1f%%)', $change, $pct) ?>
                        </span>
                    <?php else: ?>
                        <small class="text-muted">—</small>
                    <?php endif; ?>
                </td>
                <td><?= badge($r['availability'] ?? 'unknown') ?></td>
                <td><?= $r['is_active'] ? '<i class="bi bi-check text-success"></i>' : '<i class="bi bi-x text-danger"></i>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= pagination($total, $perPage, $currentPage, url('price_history', array_filter(['supplier_id' => $supplierId, 'product_id' => $productId, 'days' => $days]))) ?>
