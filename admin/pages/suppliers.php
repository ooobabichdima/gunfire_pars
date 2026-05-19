<?php
$suppliers = $db->fetchAll(
    "SELECT s.*,
        (SELECT COUNT(*) FROM supplier_offers WHERE supplier_id=s.id AND is_active=1) as offer_count,
        (SELECT MAX(last_seen_at) FROM supplier_offers WHERE supplier_id=s.id) as last_activity
     FROM suppliers s ORDER BY s.id"
);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Suppliers</h4>
    <a href="<?= url('supplier_edit') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> Add Supplier</a>
</div>

<table class="table table-hover">
    <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>URL</th><th>Active</th><th>Offers</th><th>Last Activity</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($suppliers as $s): ?>
        <tr>
            <td><code><?= esc($s['code']) ?></code></td>
            <td><?= esc($s['name']) ?></td>
            <td><?= badge($s['type']) ?></td>
            <td><small><?= esc($s['base_url'] ?? '') ?></small></td>
            <td>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" <?= $s['is_active'] ? 'checked' : '' ?>
                           onchange="toggleSupplier(<?= $s['id'] ?>, this.checked)">
                </div>
            </td>
            <td><?= format_number($s['offer_count']) ?></td>
            <td><?= time_ago($s['last_activity']) ?></td>
            <td>
                <a href="<?= url('supplier_edit', ['id' => $s['id']]) ?>" class="btn btn-outline-secondary btn-xs">Edit</a>
                <a href="<?= url('schedules', ['supplier_id' => $s['id']]) ?>" class="btn btn-outline-info btn-xs">Schedule</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
