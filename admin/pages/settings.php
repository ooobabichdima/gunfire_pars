<?php
use App\Admin\Auth;

$auth = new Auth($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$auth->login($current)) {
            flash_set('error', 'Current password is incorrect');
        } elseif (strlen($newPass) < 4) {
            flash_set('error', 'Password must be at least 4 characters');
        } elseif ($newPass !== $confirm) {
            flash_set('error', 'Passwords do not match');
        } else {
            $auth->setPassword($newPass);
            flash_set('success', 'Password changed');
        }
    }

    if ($action === 'save_settings') {
        $settings = [
            'log_level'     => $_POST['log_level'] ?? 'info',
            'default_batch' => $_POST['default_batch'] ?? '50',
        ];
        foreach ($settings as $key => $value) {
            $db->query(
                "INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$key, $value]
            );
        }
        flash_set('success', 'Settings saved');
    }

    header('Location: ' . url('settings'));
    exit;
}

$currentSettings = [];
$rows = $db->fetchAll("SELECT `key`, `value` FROM settings");
foreach ($rows as $r) { $currentSettings[$r['key']] = $r['value']; }
?>

<h4 class="mb-3">Settings</h4>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">Change Password</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-2">
                        <input type="password" name="current_password" class="form-control form-control-sm" placeholder="Current password" required>
                    </div>
                    <div class="mb-2">
                        <input type="password" name="new_password" class="form-control form-control-sm" placeholder="New password" required>
                    </div>
                    <div class="mb-2">
                        <input type="password" name="confirm_password" class="form-control form-control-sm" placeholder="Confirm new password" required>
                    </div>
                    <button class="btn btn-primary btn-sm">Change Password</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">Global Settings</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_settings">
                    <div class="mb-2">
                        <label class="form-label">Log Level</label>
                        <select name="log_level" class="form-select form-select-sm">
                            <?php foreach (['debug','info','warning','error'] as $lv): ?>
                                <option value="<?= $lv ?>" <?= ($currentSettings['log_level'] ?? 'info') === $lv ? 'selected' : '' ?>><?= $lv ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Default Batch Size</label>
                        <input type="number" name="default_batch" class="form-control form-control-sm" value="<?= esc($currentSettings['default_batch'] ?? '50') ?>">
                    </div>
                    <button class="btn btn-primary btn-sm">Save</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">System Info</div>
            <div class="card-body">
                <small>
                    PHP: <?= PHP_VERSION ?><br>
                    OS: <?= PHP_OS ?><br>
                    Project: <?= dirname(__DIR__, 2) ?><br>
                    Cron scheduler: <code>* * * * * php <?= dirname(__DIR__, 2) ?>/cron_scheduler.php</code>
                </small>
            </div>
        </div>
    </div>
</div>
