<?php
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$perPage = 30;
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;

$where = [];
$params = [];
if ($supplierId > 0) { $where[] = 'pq.supplier_id = ?'; $params[] = $supplierId; }
if ($status) { $where[] = 'pq.status = ?'; $params[] = $status; }
if ($type) { $where[] = 'pq.type = ?'; $params[] = $type; }
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->fetchColumn("SELECT COUNT(*) FROM parse_queue pq {$whereStr}", $params);
$items = $db->fetchAll(
    "SELECT pq.*, s.code AS supplier_code FROM parse_queue pq
     LEFT JOIN suppliers s ON s.id = pq.supplier_id
     {$whereStr} ORDER BY pq.id DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$suppliers = $db->fetchAll("SELECT id, code, name FROM suppliers ORDER BY code");
$stats = $db->fetchAll("SELECT status, COUNT(*) as cnt FROM parse_queue GROUP BY status");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['bulk_action'] ?? '';
    $ids = array_map('intval', $_POST['ids'] ?? []);
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($action === 'retry') {
            $db->query("UPDATE parse_queue SET status='new', retry_count=0, error_message=NULL WHERE id IN ({$placeholders})", $ids);
        } elseif ($action === 'delete') {
            $db->query("DELETE FROM parse_queue WHERE id IN ({$placeholders})", $ids);
        }
        flash_set('success', ucfirst($action) . ': ' . count($ids) . ' items');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><?= t('parse_queue') ?></h4>
    <div>
        <?php foreach ($stats as $st): ?>
            <?= badge($st['status']) ?> <?= format_number($st['cnt']) ?>
        <?php endforeach; ?>
    </div>
</div>

<form class="row g-2 mb-3">
    <input type="hidden" name="page" value="queue">
    <div class="col-auto">
        <select name="supplier_id" class="form-select form-select-sm">
            <option value=""><?= t('all_suppliers') ?></option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $supplierId == $s['id'] ? 'selected' : '' ?>><?= esc($s['code']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
            <option value=""><?= t('all_statuses') ?></option>
            <?php foreach (['new','processing','done','error','skipped'] as $st): ?>
                <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <select name="type" class="form-select form-select-sm">
            <option value=""><?= t('all_types') ?></option>
            <?php foreach (['category','listing','product'] as $t): ?>
                <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('filter') ?></button></div>
</form>

<form method="POST">
    <?= csrf_field() ?>
    <div class="mb-2">
        <button name="bulk_action" value="retry" class="btn btn-outline-warning btn-sm"><?= t('retry_selected') ?></button>
        <button name="bulk_action" value="delete" class="btn btn-outline-danger btn-sm" onclick="return confirm('<?= t('confirm_delete') ?>')"><?= t('delete_selected') ?></button>
    </div>
    <table class="table table-sm table-hover">
        <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('input[name=\'ids[]\']').forEach(c=>c.checked=this.checked)"></th><th>ID</th><th><?= t('suppliers') ?></th><th><?= t('type') ?></th><th>URL</th><th><?= t('status') ?></th><th><?= t('retries') ?></th><th><?= t('error') ?></th><th><?= t('created') ?></th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?= $item['id'] ?>"></td>
                <td><?= $item['id'] ?></td>
                <td><code><?= esc($item['supplier_code'] ?? '') ?></code></td>
                <td><?= badge($item['type']) ?></td>
                <td><a href="<?= esc($item['url']) ?>" target="_blank" class="text-decoration-none" title="<?= esc($item['url']) ?>"><small><?= esc(mb_substr($item['url'], 0, 60)) ?></small> <i class="bi bi-box-arrow-up-right" style="font-size:.65rem"></i></a></td>
                <td><?= badge($item['status']) ?></td>
                <td><?= $item['retry_count'] ?>/<?= $item['max_retries'] ?></td>
                <td><small class="text-danger"><?= esc(mb_substr($item['error_message'] ?? '', 0, 50)) ?></small></td>
                <td><?= time_ago($item['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</form>

<?= pagination($total, $perPage, $currentPage, url('queue', array_filter(['supplier_id' => $supplierId, 'status' => $status, 'type' => $type]))) ?>
