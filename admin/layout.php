<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle ?? 'Admin') ?> - <?= t('app_name') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .sidebar { min-height: 100vh; background: #212529; }
        .sidebar .nav-link { color: #adb5bd; padding: .6rem 1rem; font-size: .9rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: #343a40; }
        .sidebar .nav-link i { width: 24px; }
        .content { padding: 1.5rem; }
        .stat-card { border-left: 4px solid; }
        .stat-card.primary { border-color: #0d6efd; }
        .stat-card.success { border-color: #198754; }
        .stat-card.warning { border-color: #ffc107; }
        .stat-card.danger { border-color: #dc3545; }
        .table td, .table th { vertical-align: middle; font-size: .875rem; }
        .btn-xs { padding: .15rem .4rem; font-size: .75rem; }
    </style>
</head>
<body>
<div class="d-flex">
    <div class="sidebar d-flex flex-column p-3" style="width: 230px; flex-shrink: 0;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="<?= url('dashboard') ?>" class="text-white text-decoration-none">
                <strong><i class="bi bi-box-seam"></i> <?= t('app_name') ?></strong>
            </a>
            <?= langSwitcher() ?>
        </div>
        <?php $unreadAlerts = (int)($db->fetchColumn("SELECT COUNT(*) FROM price_alerts WHERE is_read = 0") ?? 0); ?>
        <ul class="nav flex-column">
            <li><a class="nav-link <?= is_page('dashboard') ? 'active' : '' ?>" href="<?= url('dashboard') ?>"><i class="bi bi-speedometer2"></i> <?= t('nav_dashboard') ?></a></li>
            <li><a class="nav-link <?= is_page('suppliers') || is_page('supplier_edit') ? 'active' : '' ?>" href="<?= url('suppliers') ?>"><i class="bi bi-truck"></i> <?= t('nav_suppliers') ?></a></li>
            <li><a class="nav-link <?= is_page('schedules') ? 'active' : '' ?>" href="<?= url('schedules') ?>"><i class="bi bi-clock-history"></i> <?= t('nav_schedules') ?></a></li>
            <li><a class="nav-link <?= is_page('queue') ? 'active' : '' ?>" href="<?= url('queue') ?>"><i class="bi bi-list-task"></i> <?= t('nav_queue') ?></a></li>
            <li class="mt-2"><small class="text-muted px-3"><?= t('nav_data') ?></small></li>
            <li><a class="nav-link <?= is_page('catalog') || is_page('product') ? 'active' : '' ?>" href="<?= url('catalog') ?>"><i class="bi bi-grid-3x3-gap"></i> <?= t('nav_catalog') ?></a></li>
            <li><a class="nav-link <?= is_page('categories') ? 'active' : '' ?>" href="<?= url('categories') ?>"><i class="bi bi-diagram-3"></i> Категорії</a></li>
            <li><a class="nav-link <?= is_page('offers') ? 'active' : '' ?>" href="<?= url('offers') ?>"><i class="bi bi-tags"></i> <?= t('nav_offers') ?></a></li>
            <li><a class="nav-link <?= is_page('prices') ? 'active' : '' ?>" href="<?= url('prices') ?>"><i class="bi bi-currency-exchange"></i> <?= t('nav_prices') ?></a></li>
            <li><a class="nav-link <?= is_page('price_history') ? 'active' : '' ?>" href="<?= url('price_history') ?>"><i class="bi bi-graph-up"></i> <?= t('nav_price_history') ?></a></li>
            <li><a class="nav-link <?= is_page('alerts') ? 'active' : '' ?>" href="<?= url('alerts') ?>"><i class="bi bi-bell"></i> <?= t('nav_alerts') ?> <?php if ($unreadAlerts > 0): ?><span class="badge bg-danger rounded-pill"><?= $unreadAlerts ?></span><?php endif; ?></a></li>
            <li><a class="nav-link <?= is_page('analytics') ? 'active' : '' ?>" href="<?= url('analytics') ?>"><i class="bi bi-bar-chart-line"></i> <?= t('nav_analytics') ?></a></li>
            <li><a class="nav-link <?= is_page('content') ? 'active' : '' ?>" href="<?= url('content') ?>"><i class="bi bi-magic"></i> Контент</a></li>
            <li class="mt-2"><small class="text-muted px-3"><?= t('nav_system') ?></small></li>
            <li><a class="nav-link <?= is_page('jobs') ? 'active' : '' ?>" href="<?= url('jobs') ?>"><i class="bi bi-play-circle"></i> <?= t('nav_jobs') ?></a></li>
            <li><a class="nav-link <?= is_page('logs') ? 'active' : '' ?>" href="<?= url('logs') ?>"><i class="bi bi-journal-text"></i> <?= t('nav_logs') ?></a></li>
            <li><a class="nav-link <?= is_page('settings') ? 'active' : '' ?>" href="<?= url('settings') ?>"><i class="bi bi-gear"></i> <?= t('nav_settings') ?></a></li>
            <li><a class="nav-link text-danger" href="<?= url('login') ?>&action=logout"><i class="bi bi-box-arrow-left"></i> <?= t('nav_logout') ?></a></li>
        </ul>
    </div>

    <div class="flex-grow-1 content">
        <?php foreach (flash_get() as $type => $msg): ?>
            <div class="alert alert-<?= $type === 'error' ? 'danger' : esc($type) ?> alert-dismissible fade show" role="alert">
                <?= esc($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>

        <?= $pageContent ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF_TOKEN = '<?= esc($_SESSION['csrf_token'] ?? '') ?>';

function apiPost(action, data = {}) {
    data.action = action;
    data._csrf = CSRF_TOKEN;
    return fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN},
        body: JSON.stringify(data)
    }).then(r => r.json());
}

function runJob(supplierId, jobType, batchSize = 50) {
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    apiPost('run_job', {supplier_id: supplierId, job_type: jobType, batch_size: batchSize})
        .then(r => {
            if (r.error) { alert(r.error); btn.disabled = false; btn.textContent = '<?= t('run_now') ?>'; }
            else { location.reload(); }
        })
        .catch(() => { btn.disabled = false; btn.textContent = '<?= t('run_now') ?>'; });
}

function stopJob(jobRunId) {
    if (!confirm('<?= t('stop') ?>?')) return;
    apiPost('stop_job', {job_run_id: jobRunId}).then(() => location.reload());
}

function toggleSupplier(supplierId, isActive) {
    apiPost('toggle_supplier', {supplier_id: supplierId, is_active: isActive ? 1 : 0});
}
</script>
</body>
</html>
