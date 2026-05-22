<?php
$templates = $db->fetchAll("SELECT * FROM content_templates ORDER BY type, code");
$action = $_GET['action'] ?? '';
$productId = (int)($_GET['product_id'] ?? 0);

// Save template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save_template') {
        $db->query(
            "INSERT INTO content_templates (code, name, prompt, type, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE name=VALUES(name), prompt=VALUES(prompt), type=VALUES(type), updated_at=NOW()",
            [$_POST['code'], $_POST['name'], $_POST['prompt'], $_POST['type']]
        );
        flash_set('success', 'Шаблон збережено');
        header('Location: ' . url('content'));
        exit;
    }

    if ($postAction === 'generate_single') {
        $pid = (int)$_POST['product_id'];
        $tpl = $_POST['template_code'];

        $apiKey = $db->fetchOne("SELECT value FROM settings WHERE `key` = 'ai_api_key'")['value'] ?? '';
        $gen = new \App\Services\ContentGenerator($db, $logger, $apiKey);

        $result = $gen->generateForProduct($pid, $tpl);
        if ($result) {
            $gen->applyResult($pid, $result['template_type'], $result['result']);
            flash_set('success', 'Контент згенеровано');
        } else {
            flash_set('error', 'Помилка генерації');
        }
        header('Location: ' . url('product', ['id' => $pid]));
        exit;
    }

    if ($postAction === 'generate_bulk') {
        $tpl = $_POST['template_code'];
        $limit = (int)($_POST['limit'] ?? 10);

        $apiKey = $db->fetchOne("SELECT value FROM settings WHERE `key` = 'ai_api_key'")['value'] ?? '';
        $gen = new \App\Services\ContentGenerator($db, $logger, $apiKey);
        $stats = $gen->generateBulk($tpl, $limit);

        flash_set('success', "Згенеровано: {$stats['success']}, помилок: {$stats['errors']}");
        header('Location: ' . url('content'));
        exit;
    }

    if ($postAction === 'save_api_key') {
        $db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('ai_api_key', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$_POST['api_key']]
        );
        $db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('ai_model', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$_POST['ai_model'] ?? 'claude-sonnet-4-20250514']
        );
        flash_set('success', 'API ключ збережено');
        header('Location: ' . url('content'));
        exit;
    }
}

$apiKey = $db->fetchOne("SELECT value FROM settings WHERE `key` = 'ai_api_key'")['value'] ?? '';
$aiModel = $db->fetchOne("SELECT value FROM settings WHERE `key` = 'ai_model'")['value'] ?? 'claude-sonnet-4-20250514';

$noName = (int)$db->fetchColumn("SELECT COUNT(*) FROM catalog_products WHERE store_name IS NULL");
$noSeo = (int)$db->fetchColumn("SELECT COUNT(*) FROM catalog_products WHERE meta_title IS NULL");
$noCard = (int)$db->fetchColumn("SELECT COUNT(*) FROM catalog_products WHERE store_description IS NULL");
$total = (int)$db->fetchColumn("SELECT COUNT(*) FROM catalog_products");

// Recent generated
$recent = $db->fetchAll(
    "SELECT id, name, store_name, meta_title, content_generated_at
     FROM catalog_products WHERE content_generated_at IS NOT NULL
     ORDER BY content_generated_at DESC LIMIT 10"
);
?>

<h4 class="mb-3"><i class="bi bi-magic"></i> Генерація контенту</h4>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card primary"><div class="card-body py-2">
            <small class="text-muted">Потребують назви</small>
            <div class="fs-5 fw-bold"><?= format_number($noName) ?> <small class="text-muted">/ <?= format_number($total) ?></small></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning"><div class="card-body py-2">
            <small class="text-muted">Без SEO</small>
            <div class="fs-5 fw-bold"><?= format_number($noSeo) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card danger"><div class="card-body py-2">
            <small class="text-muted">Без картки</small>
            <div class="fs-5 fw-bold"><?= format_number($noCard) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card"><div class="card-body py-2">
            <small class="text-muted">AI API</small>
            <div class="fs-6 fw-bold"><?= $apiKey ? '<span class="text-success">Підключено</span>' : '<span class="text-danger">Не налаштовано</span>' ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <!-- Bulk Generation -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header"><strong><i class="bi bi-lightning"></i> Масова генерація</strong></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_bulk">
                    <div class="mb-2">
                        <label class="form-label">Шаблон</label>
                        <select name="template_code" class="form-select form-select-sm">
                            <?php foreach ($templates as $tpl): ?>
                                <option value="<?= esc($tpl['code']) ?>"><?= esc($tpl['name']) ?> (<?= $tpl['type'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Кількість товарів</label>
                        <input type="number" name="limit" class="form-control form-control-sm" value="10" min="1" max="100">
                    </div>
                    <button class="btn btn-primary btn-sm"><i class="bi bi-play"></i> Згенерувати</button>
                    <?php if (!$apiKey): ?>
                        <small class="text-warning d-block mt-1">Без API ключа — базова генерація (шаблони)</small>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- API Key -->
        <div class="card mb-3">
            <div class="card-header"><strong>AI API (Claude)</strong></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_api_key">
                    <div class="mb-2">
                        <label class="form-label">API Key (Anthropic)</label>
                        <input type="password" name="api_key" class="form-control form-control-sm" value="<?= esc($apiKey) ?>" placeholder="sk-ant-...">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Модель</label>
                        <select name="ai_model" class="form-select form-select-sm">
                            <?php foreach (['claude-sonnet-4-20250514', 'claude-haiku-4-5-20251001', 'claude-opus-4-6'] as $m): ?>
                                <option value="<?= $m ?>" <?= $aiModel === $m ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-outline-primary btn-sm">Зберегти</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Templates -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <strong>Шаблони промптів</strong>
            </div>
            <div class="card-body p-0">
                <div class="accordion" id="templatesAccordion">
                    <?php foreach ($templates as $i => $tpl): ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#tpl<?= $i ?>">
                                <span class="badge bg-secondary me-2"><?= esc($tpl['type']) ?></span> <?= esc($tpl['name']) ?>
                            </button>
                        </h2>
                        <div id="tpl<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#templatesAccordion">
                            <div class="accordion-body p-2">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="save_template">
                                    <input type="hidden" name="code" value="<?= esc($tpl['code']) ?>">
                                    <input type="hidden" name="type" value="<?= esc($tpl['type']) ?>">
                                    <div class="mb-2">
                                        <input type="text" name="name" class="form-control form-control-sm" value="<?= esc($tpl['name']) ?>">
                                    </div>
                                    <div class="mb-2">
                                        <textarea name="prompt" class="form-control form-control-sm font-monospace" rows="8"><?= esc($tpl['prompt']) ?></textarea>
                                    </div>
                                    <small class="text-muted">Змінні: {name} {brand} {model} {category} {description} {specifications} {price} {currency}</small>
                                    <div class="mt-2">
                                        <button class="btn btn-outline-primary btn-xs">Зберегти</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent -->
<?php if (!empty($recent)): ?>
<div class="card mt-3">
    <div class="card-header"><strong>Останні згенеровані</strong></div>
    <table class="table table-sm mb-0">
        <thead><tr><th>ID</th><th>Оригінал</th><th>Назва для магазину</th><th>SEO Title</th><th>Дата</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr style="cursor:pointer" onclick="location='<?= url('product', ['id' => $r['id']]) ?>'">
                <td><?= $r['id'] ?></td>
                <td><small><?= esc(mb_substr($r['name'], 0, 40)) ?></small></td>
                <td class="text-success"><small><?= esc(mb_substr($r['store_name'] ?? '—', 0, 40)) ?></small></td>
                <td><small><?= esc(mb_substr($r['meta_title'] ?? '—', 0, 40)) ?></small></td>
                <td><small><?= time_ago($r['content_generated_at']) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
