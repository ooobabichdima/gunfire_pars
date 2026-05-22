<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/bootstrap.php';

use App\Admin\Auth;

$auth = new Auth($db);

$page = $_GET['page'] ?? 'dashboard';

if ($page === 'login') {
    require __DIR__ . '/../admin/auth.php';
    exit;
}

if (!$auth->isAuthenticated()) {
    header('Location: index.php?page=login');
    exit;
}

$pages = [
    'dashboard'      => ['file' => 'dashboard.php',      'title' => 'Dashboard'],
    'suppliers'      => ['file' => 'suppliers.php',       'title' => 'Suppliers'],
    'supplier_edit'  => ['file' => 'supplier_edit.php',   'title' => 'Edit Supplier'],
    'schedules'      => ['file' => 'schedules.php',       'title' => 'Schedules'],
    'queue'          => ['file' => 'queue.php',           'title' => 'Parse Queue'],
    'catalog'        => ['file' => 'catalog.php',         'title' => 'Catalog'],
    'categories'     => ['file' => 'categories.php',      'title' => 'Categories'],
    'product'        => ['file' => 'product_detail.php',  'title' => 'Product'],
    'offers'         => ['file' => 'offers.php',          'title' => 'All Offers'],
    'prices'         => ['file' => 'prices.php',          'title' => 'Price Comparison'],
    'price_history'  => ['file' => 'price_history.php',   'title' => 'Price History'],
    'alerts'         => ['file' => 'alerts.php',          'title' => 'Alerts'],
    'analytics'      => ['file' => 'analytics.php',       'title' => 'Analytics'],
    'content'        => ['file' => 'content.php',         'title' => 'Content'],
    'jobs'           => ['file' => 'jobs.php',            'title' => 'Job Runs'],
    'logs'           => ['file' => 'logs.php',            'title' => 'Logs'],
    'settings'       => ['file' => 'settings.php',        'title' => 'Settings'],
];

if (!isset($pages[$page])) {
    $page = 'dashboard';
}

$pageTitle = $pages[$page]['title'];
$pageFile = __DIR__ . '/../admin/pages/' . $pages[$page]['file'];

ob_start();
require $pageFile;
$pageContent = ob_get_clean();

require __DIR__ . '/../admin/layout.php';
