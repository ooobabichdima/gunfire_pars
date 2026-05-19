<?php
$id = (int)($_GET['id'] ?? 0);
$supplier = $id > 0 ? $db->fetchOne('SELECT * FROM suppliers WHERE id = ?', [$id]) : null;
$isNew = $supplier === null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $data = [
        'code'     => trim($_POST['code'] ?? ''),
        'name'     => trim($_POST['name'] ?? ''),
        'type'     => $_POST['type'] ?? 'site',
        'base_url' => trim($_POST['base_url'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    $configFields = ['currency', 'locale', 'xls_base_url', 'xls_auth_login', 'xls_auth_password',
                     'photo_base_url', 'delay_min_ms', 'delay_max_ms', 'batch_size'];
    $configJson = [];
    foreach ($configFields as $f) {
        $val = trim($_POST['config_' . $f] ?? '');
        if ($val !== '') {
            $configJson[$f] = is_numeric($val) ? (int)$val : $val;
        }
    }

    $extraJson = trim($_POST['config_extra_json'] ?? '');
    if (!empty($extraJson)) {
        $extra = json_decode($extraJson, true);
        if (is_array($extra)) {
            $configJson = array_merge($configJson, $extra);
        }
    }

    $data['config_json'] = json_encode($configJson, JSON_UNESCAPED_UNICODE);

    if ($isNew) {
        if (empty($data['code'])) {
            flash_set('error', 'Code is required');
        } else {
            $db->insert('suppliers', array_merge($data, [
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            flash_set('success', 'Supplier created');
            header('Location: ' . url('suppliers'));
            exit;
        }
    } else {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $db->update('suppliers', $data, 'id = ?', [$id]);
        flash_set('success', 'Supplier updated');
        header('Location: ' . url('suppliers'));
        exit;
    }
}

$cfg = json_decode($supplier['config_json'] ?? '{}', true) ?: [];
?>

<h4 class="mb-3"><?= $isNew ? 'Add Supplier' : 'Edit: ' . esc($supplier['name']) ?></h4>

<form method="POST" class="row g-3" style="max-width: 800px;">
    <?= csrf_field() ?>

    <div class="col-md-4">
        <label class="form-label">Code</label>
        <input type="text" name="code" class="form-control" value="<?= esc($supplier['code'] ?? '') ?>" <?= $isNew ? '' : 'readonly' ?> required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" value="<?= esc($supplier['name'] ?? '') ?>" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Type</label>
        <select name="type" class="form-select">
            <?php foreach (['site', 'api', 'csv', 'b2b'] as $t): ?>
                <option value="<?= $t ?>" <?= ($supplier['type'] ?? 'site') === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-md-8">
        <label class="form-label">Base URL</label>
        <input type="text" name="base_url" class="form-control" value="<?= esc($supplier['base_url'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Active</label>
        <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" name="is_active" <?= ($supplier['is_active'] ?? 1) ? 'checked' : '' ?>>
        </div>
    </div>

    <div class="col-12"><hr><h6>Supplier Config</h6></div>

    <div class="col-md-3">
        <label class="form-label">Currency</label>
        <input type="text" name="config_currency" class="form-control" value="<?= esc($cfg['currency'] ?? '') ?>" placeholder="PLN, UAH, EUR">
    </div>
    <div class="col-md-3">
        <label class="form-label">Locale</label>
        <input type="text" name="config_locale" class="form-control" value="<?= esc($cfg['locale'] ?? '') ?>" placeholder="en, pl, ua">
    </div>
    <div class="col-md-3">
        <label class="form-label">Batch Size</label>
        <input type="number" name="config_batch_size" class="form-control" value="<?= esc((string)($cfg['batch_size'] ?? '')) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Delay Min (ms)</label>
        <input type="number" name="config_delay_min_ms" class="form-control" value="<?= esc((string)($cfg['delay_min_ms'] ?? '')) ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label">XLS Base URL</label>
        <input type="text" name="config_xls_base_url" class="form-control" value="<?= esc($cfg['xls_base_url'] ?? '') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">XLS Login</label>
        <input type="text" name="config_xls_auth_login" class="form-control" value="<?= esc($cfg['xls_auth_login'] ?? '') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">XLS Password</label>
        <input type="text" name="config_xls_auth_password" class="form-control" value="<?= esc($cfg['xls_auth_password'] ?? '') ?>">
    </div>
    <div class="col-md-6">
        <label class="form-label">Photo Base URL</label>
        <input type="text" name="config_photo_base_url" class="form-control" value="<?= esc($cfg['photo_base_url'] ?? '') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Delay Max (ms)</label>
        <input type="number" name="config_delay_max_ms" class="form-control" value="<?= esc((string)($cfg['delay_max_ms'] ?? '')) ?>">
    </div>

    <div class="col-12">
        <label class="form-label">Extra JSON Config</label>
        <textarea name="config_extra_json" class="form-control font-monospace" rows="3" placeholder='{"key": "value"}'><?php
            $known = ['currency','locale','xls_base_url','xls_auth_login','xls_auth_password','photo_base_url','delay_min_ms','delay_max_ms','batch_size'];
            $extra = array_diff_key($cfg, array_flip($known));
            echo !empty($extra) ? esc(json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) : '';
        ?></textarea>
    </div>

    <div class="col-12">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create' : 'Save' ?></button>
        <a href="<?= url('suppliers') ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
