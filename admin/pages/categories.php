<?php
// Save Ukrainian name
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $catId = (int)($_POST['cat_id'] ?? 0);
    $nameUk = trim($_POST['name_uk'] ?? '');
    if ($catId > 0) {
        $db->update('categories', ['name_uk' => $nameUk ?: null, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$catId]);
        flash_set('success', 'Збережено');
        header('Location: ' . url('categories'));
        exit;
    }

    // Bulk save
    if (isset($_POST['bulk_names'])) {
        foreach ($_POST['bulk_names'] as $id => $uk) {
            $uk = trim($uk);
            if (!empty($uk)) {
                $db->update('categories', ['name_uk' => $uk, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$id]);
            }
        }
        flash_set('success', 'Збережено');
        header('Location: ' . url('categories'));
        exit;
    }
}

$categories = $db->fetchAll(
    "SELECT c.*,
        (SELECT COUNT(*) FROM product_categories pc WHERE pc.category_id = c.id) as product_count,
        (SELECT COUNT(*) FROM categories cc WHERE cc.parent_id = c.id) as child_count,
        p.name as parent_name
     FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     ORDER BY c.level ASC, c.name ASC"
);

// Build tree
$tree = [];
$byId = [];
foreach ($categories as &$cat) {
    $cat['children'] = [];
    $byId[$cat['id']] = &$cat;
}
unset($cat);

foreach ($categories as &$cat) {
    if ($cat['parent_id'] && isset($byId[$cat['parent_id']])) {
        $byId[$cat['parent_id']]['children'][] = &$cat;
    } else {
        $tree[] = &$cat;
    }
}
unset($cat);

$totalCategories = count($categories);
$totalProducts = (int)$db->fetchColumn("SELECT COUNT(DISTINCT product_id) FROM product_categories");
$noUkName = 0;
foreach ($categories as $c) { if (empty($c['name_uk'])) $noUkName++; }

function renderTreeEditable(array $nodes, int $depth = 0): void {
    foreach ($nodes as $cat) {
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
        $icon = !empty($cat['children']) ? '<i class="bi bi-folder2-open text-warning"></i>' : '<i class="bi bi-folder text-muted"></i>';
        $badge = $cat['product_count'] > 0 ? '<span class="badge bg-primary rounded-pill">' . $cat['product_count'] . '</span>' : '';
        $nameUk = $cat['name_uk'] ?? '';

        echo '<tr>';
        echo '<td>' . $cat['id'] . '</td>';
        echo '<td>' . $indent . $icon . ' ' . htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td><input type="text" name="bulk_names[' . $cat['id'] . ']" class="form-control form-control-sm" value="' . htmlspecialchars($nameUk, ENT_QUOTES, 'UTF-8') . '" placeholder="Укр назва..."' . ($nameUk ? ' style="border-color:#198754"' : '') . '></td>';
        echo '<td>' . $badge . '</td>';
        echo '<td>' . ($cat['child_count'] > 0 ? $cat['child_count'] : '') . '</td>';
        echo '</tr>';

        if (!empty($cat['children'])) {
            renderTreeEditable($cat['children'], $depth + 1);
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-diagram-3"></i> Категорії <small class="text-muted">(<?= $totalCategories ?> кат., <?= format_number($totalProducts) ?> товарів)</small></h4>
    <?php if ($noUkName > 0): ?>
        <span class="badge bg-warning">Без UA назви: <?= $noUkName ?></span>
    <?php endif; ?>
</div>

<?php if (!empty($categories)): ?>
<form method="POST">
    <?= csrf_field() ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th style="width:50px">ID</th>
                    <th>Назва (оригінал)</th>
                    <th style="width:300px">Назва UA</th>
                    <th style="width:80px">Товарів</th>
                    <th style="width:80px">Підкат.</th>
                </tr>
            </thead>
            <tbody>
                <?php renderTreeEditable($tree); ?>
            </tbody>
        </table>
    </div>
    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Зберегти всі назви</button>
</form>
<?php else: ?>
    <div class="alert alert-info">
        Категорії ще не побудовані. Запустіть: <code>php build_relations.php --limit=6000</code>
    </div>
<?php endif; ?>
