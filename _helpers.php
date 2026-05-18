<?php

if (!function_exists('getActiveNav')) {
function getActiveNav($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}
}

if (!function_exists('getStatusBadge')) {
function getStatusBadge($status) {
    $classes = [
        'pending' => 'bg-warning text-dark',
        'approved' => 'bg-info',
        'rejected' => 'bg-danger',
        'processed' => 'bg-success',
        'draft' => 'bg-secondary',
        'confirmed' => 'bg-success',
        'cancelled' => 'bg-danger',
    ];
    $class = $classes[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $class . '">' . (function_exists('escape') ? escape(ucfirst($status)) : ucfirst($status)) . '</span>';
}
}

if (!function_exists('getStockTypeLabel')) {
function getStockTypeLabel($type) {
    $labels = [
        'in' => '<span class="badge bg-success">Прихід</span>',
        'out' => '<span class="badge bg-danger">Витрата</span>',
        'return_in' => '<span class="badge bg-info">Повернення на склад</span>',
        'return_out' => '<span class="badge bg-warning text-dark">Повернення постачальнику</span>',
        'adjustment' => '<span class="badge bg-secondary">Корекція</span>',
    ];
    return $labels[$type] ?? (function_exists('escape') ? escape($type) : $type);
}
}

if (!function_exists('paginate')) {
function paginate($total, $perPage, $currentPage) {
    $totalPages = ceil($total / $perPage);
    $offset = ($currentPage - 1) * $perPage;
    return [
        'total' => $total,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => max(1, $totalPages),
        'offset' => $offset,
    ];
}
}

if (!function_exists('renderPagination')) {
function renderPagination($baseUrl, $pagination) {
    if ($pagination['total_pages'] <= 1) return '';
    $html = '<nav><ul class="pagination justify-content-center">';
    $html .= '<li class="page-item ' . ($pagination['current_page'] <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&page=' . ($pagination['current_page'] - 1) . '">&laquo;</a></li>';
    for ($i = 1; $i <= $pagination['total_pages']; $i++) {
        $html .= '<li class="page-item ' . ($i === $pagination['current_page'] ? 'active' : '') . '">';
        $html .= '<a class="page-link" href="' . $baseUrl . '&page=' . $i . '">' . $i . '</a></li>';
    }
    $html .= '<li class="page-item ' . ($pagination['current_page'] >= $pagination['total_pages'] ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&page=' . ($pagination['current_page'] + 1) . '">&raquo;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}
}
