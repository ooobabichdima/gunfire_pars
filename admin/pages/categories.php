<?php
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

function renderTree(array $nodes, int $depth = 0): void {
    foreach ($nodes as $cat) {
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
        $icon = !empty($cat['children']) ? '<i class="bi bi-folder2-open text-warning"></i>' : '<i class="bi bi-folder text-muted"></i>';
        $badge = $cat['product_count'] > 0 ? '<span class="badge bg-primary rounded-pill">' . $cat['product_count'] . '</span>' : '';

        echo '<tr>';
        echo '<td>' . $cat['id'] . '</td>';
        echo '<td>' . $indent . $icon . ' ' . htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td><small class="text-muted">' . htmlspecialchars($cat['slug'], ENT_QUOTES, 'UTF-8') . '</small></td>';
        echo '<td>' . $cat['level'] . '</td>';
        echo '<td>' . $badge . '</td>';
        echo '<td>' . ($cat['child_count'] > 0 ? $cat['child_count'] : '') . '</td>';
        echo '<td><small class="text-muted">' . htmlspecialchars($cat['parent_name'] ?? '—', ENT_QUOTES, 'UTF-8') . '</small></td>';
        echo '</tr>';

        if (!empty($cat['children'])) {
            renderTree($cat['children'], $depth + 1);
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-diagram-3"></i> Категорії <small class="text-muted">(<?= $totalCategories ?> категорій, <?= format_number($totalProducts) ?> товарів)</small></h4>
</div>

<div class="table-responsive">
    <table class="table table-sm table-hover">
        <thead>
            <tr>
                <th style="width:50px">ID</th>
                <th>Назва</th>
                <th>Slug</th>
                <th style="width:60px">Рівень</th>
                <th style="width:80px">Товарів</th>
                <th style="width:80px">Підкат.</th>
                <th>Батьківська</th>
            </tr>
        </thead>
        <tbody>
            <?php renderTree($tree); ?>
        </tbody>
    </table>
</div>

<?php if (empty($categories)): ?>
    <div class="alert alert-info">
        Категорії ще не побудовані. Запустіть: <code>php build_relations.php --limit=6000</code>
    </div>
<?php endif; ?>
