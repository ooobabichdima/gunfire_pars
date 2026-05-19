<?php

declare(strict_types=1);

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . esc($_SESSION['csrf_token'] ?? '') . '">';
}

function verify_csrf(): bool
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function esc(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][$type] = $message;
}

function flash_get(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

function url(string $page = '', array $params = []): string
{
    $params['page'] = $page;
    return 'index.php?' . http_build_query($params);
}

function is_page(string $page): bool
{
    return ($_GET['page'] ?? 'dashboard') === $page;
}

function badge(string $status): string
{
    $colors = [
        'new' => 'primary', 'processing' => 'warning', 'done' => 'success',
        'error' => 'danger', 'skipped' => 'secondary',
        'running' => 'warning', 'completed' => 'success', 'failed' => 'danger', 'cancelled' => 'secondary',
        'in_stock' => 'success', 'out_of_stock' => 'danger', 'preorder' => 'info', 'unknown' => 'secondary',
        'site' => 'info', 'api' => 'primary', 'csv' => 'warning', 'b2b' => 'success',
    ];
    $color = $colors[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . esc($status) . '</span>';
}

function pagination(int $total, int $perPage, int $currentPage, string $baseUrl): string
{
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<nav><ul class="pagination pagination-sm">';
    $start = max(1, $currentPage - 3);
    $end = min($totalPages, $currentPage + 3);

    if ($currentPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '&p=' . ($currentPage - 1) . '">&laquo;</a></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $baseUrl . '&p=' . $i . '">' . $i . '</a></li>';
    }

    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '&p=' . ($currentPage + 1) . '">&raquo;</a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

function time_ago(?string $datetime): string
{
    if (empty($datetime)) {
        return '<span class="text-muted">never</span>';
    }

    $diff = time() - strtotime($datetime);
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return (int)($diff / 60) . 'm ago';
    if ($diff < 86400) return (int)($diff / 3600) . 'h ago';
    return (int)($diff / 86400) . 'd ago';
}

function format_number($n): string
{
    return number_format((float)$n, 0, '.', ',');
}
