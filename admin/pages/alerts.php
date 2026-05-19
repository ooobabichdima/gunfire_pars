<?php
$filter = $_GET['type'] ?? '';
$unreadOnly = !isset($_GET['all']);
$perPage = 30;
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;

// Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $db->query("UPDATE price_alerts SET is_read = 1 WHERE id IN ({$ph})", $ids);
        }
    }
    if ($action === 'mark_all_read') {
        $db->query("UPDATE price_alerts SET is_read = 1 WHERE is_read = 0");
    }
    header('Location: ' . url('alerts'));
    exit;
}

$where = [];
$params = [];
if ($unreadOnly) { $where[] = 'pa.is_read = 0'; }
if ($filter) { $where[] = 'pa.alert_type = ?'; $params[] = $filter; }
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->fetchColumn("SELECT COUNT(*) FROM price_alerts pa {$whereStr}", $params);
$unreadCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM price_alerts WHERE is_read = 0");

$alerts = $db->fetchAll(
    "SELECT pa.*, s.code AS supplier_code, cp.name AS product_name
     FROM price_alerts pa
     LEFT JOIN suppliers s ON s.id = pa.supplier_id
     LEFT JOIN catalog_products cp ON cp.id = pa.catalog_product_id
     {$whereStr} ORDER BY pa.created_at DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$typeIcons = [
    'price_drop' => '<i class="bi bi-arrow-down-circle text-success"></i>',
    'price_increase' => '<i class="bi bi-arrow-up-circle text-danger"></i>',
    'out_of_stock' => '<i class="bi bi-x-octagon text-warning"></i>',
    'back_in_stock' => '<i class="bi bi-check-circle text-success"></i>',
    'new_offer' => '<i class="bi bi-plus-circle text-info"></i>',
];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-bell"></i> <?= t('alerts') ?> <?php if ($unreadCount > 0): ?><span class="badge bg-danger"><?= $unreadCount ?></span><?php endif; ?></h4>
    <div>
        <?php if ($unreadOnly): ?>
            <a href="<?= url('alerts', ['all' => 1]) ?>" class="btn btn-outline-secondary btn-sm"><?= t('show_all') ?></a>
        <?php else: ?>
            <a href="<?= url('alerts') ?>" class="btn btn-outline-secondary btn-sm"><?= t('unread_only') ?></a>
        <?php endif; ?>
        <form method="POST" style="display:inline">
            <?= csrf_field() ?>
            <button name="action" value="mark_all_read" class="btn btn-outline-primary btn-sm"><?= t('mark_all_read') ?></button>
        </form>
    </div>
</div>

<div class="mb-3">
    <a href="<?= url('alerts') ?>" class="btn btn-sm <?= !$filter ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= t('all') ?></a>
    <?php foreach (['price_drop', 'price_increase', 'out_of_stock', 'back_in_stock', 'new_offer'] as $at): ?>
        <a href="<?= url('alerts', ['type' => $at]) ?>" class="btn btn-sm <?= $filter === $at ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $typeIcons[$at] ?? '' ?> <?= t($at) ?></a>
    <?php endforeach; ?>
</div>

<form method="POST">
    <?= csrf_field() ?>
    <div class="mb-2">
        <button name="action" value="mark_read" class="btn btn-outline-primary btn-xs"><?= t('mark_selected_read') ?></button>
    </div>
    <div class="list-group">
        <?php foreach ($alerts as $a): ?>
        <div class="list-group-item list-group-item-action <?= $a['is_read'] ? '' : 'list-group-item-light border-start border-primary border-3' ?>">
            <div class="d-flex justify-content-between">
                <div class="d-flex align-items-start gap-2">
                    <input type="checkbox" name="ids[]" value="<?= $a['id'] ?>" class="mt-1">
                    <div>
                        <span class="me-1"><?= $typeIcons[$a['alert_type']] ?? '' ?></span>
                        <strong><?= esc($a['message']) ?></strong>
                        <?php if ($a['catalog_product_id']): ?>
                            <a href="<?= url('product', ['id' => $a['catalog_product_id']]) ?>" class="ms-1 text-decoration-none">[#<?= $a['catalog_product_id'] ?> <?= esc(mb_substr($a['product_name'] ?? '', 0, 30)) ?>]</a>
                        <?php endif; ?>
                        <?php if ($a['supplier_code']): ?>
                            <code class="ms-1"><?= esc($a['supplier_code']) ?></code>
                        <?php endif; ?>
                        <?php if ($a['old_value'] && $a['new_value']): ?>
                            <small class="text-muted ms-1"><?= esc($a['old_value']) ?> → <?= esc($a['new_value']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <small class="text-muted text-nowrap ms-3"><?= time_ago($a['created_at']) ?></small>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($alerts)): ?>
            <div class="list-group-item text-center text-muted py-4"><?= t('no_alerts') ?></div>
        <?php endif; ?>
    </div>
</form>

<?= pagination($total, $perPage, $currentPage, url('alerts', array_filter(['type' => $filter, 'all' => !$unreadOnly ? 1 : null]))) ?>
