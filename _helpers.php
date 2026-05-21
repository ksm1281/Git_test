<?php

if (!function_exists('getActiveNav')) {
function getActiveNav($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}
}

if (!function_exists('getStatusBadge')) {
function getStatusBadge($status) {
    $status = strtolower($status);
    $classes = [
        'pending' => 'bg-warning text-dark',
        'approved' => 'bg-info',
        'processed' => 'bg-primary',
        'shipped' => 'bg-secondary',
        'delivered' => 'bg-success',
        'cancelled' => 'bg-danger',
    ];
    $labels = [
        'pending' => 'Очікує',
        'approved' => 'Підтверджено',
        'processed' => 'В обробці',
        'shipped' => 'Відправлено',
        'delivered' => 'Доставлено',
        'cancelled' => 'Скасовано',
    ];
    $class = $classes[$status] ?? 'bg-secondary';
    $label = $labels[$status] ?? $status;
    return '<span class="badge ' . $class . '">' . (function_exists('escape') ? escape($label) : $label) . '</span>';
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
function renderPagination($baseUrl, $pagination, $pageParam = 'page') {
    if ($pagination['total_pages'] <= 1) return '';
    $html = '<nav><ul class="pagination justify-content-center">';
    $html .= '<li class="page-item ' . ($pagination['current_page'] <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&' . $pageParam . '=' . ($pagination['current_page'] - 1) . '">&laquo;</a></li>';
    for ($i = 1; $i <= $pagination['total_pages']; $i++) {
        $html .= '<li class="page-item ' . ($i === $pagination['current_page'] ? 'active' : '') . '">';
        $html .= '<a class="page-link" href="' . $baseUrl . '&' . $pageParam . '=' . $i . '">' . $i . '</a></li>';
    }
    $html .= '<li class="page-item ' . ($pagination['current_page'] >= $pagination['total_pages'] ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&' . $pageParam . '=' . ($pagination['current_page'] + 1) . '">&raquo;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}
}

if (!function_exists('monthName')) {
function monthName($m) {
    $months = ['', 'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
    return $months[(int)$m] ?? '';
}
}

if (!function_exists('num2str')) {
function num2str($num) {
    $num = round($num, 2);
    $hryvnia = floor($num);
    $kopiyky = round(($num - $hryvnia) * 100);

    $units = ['', 'один', 'два', 'три', 'чотири', 'п\'ять', 'шість', 'сім', 'вісім', 'дев\'ять'];
    $unitsF = ['', 'одна', 'дві', 'три', 'чотири', 'п\'ять', 'шість', 'сім', 'вісім', 'дев\'ять'];
    $teens = ['десять', 'одинадцять', 'дванадцять', 'тринадцять', 'чотирнадцять', 'п\'ятнадцять', 'шістнадцять', 'сімнадцять', 'вісімнадцять', 'дев\'ятнадцять'];
    $tens = ['', '', 'двадцять', 'тридцять', 'сорок', 'п\'ятдесят', 'шістдесят', 'сімдесят', 'вісімдесят', 'дев\'яносто'];
    $hundreds = ['', 'сто', 'двісті', 'триста', 'чотириста', 'п\'ятсот', 'шістсот', 'сімсот', 'вісімсот', 'дев\'ятсот'];

    $hryvniaForms = ['гривня', 'гривні', 'гривень'];
    $kopiykyForms = ['копійка', 'копійки', 'копійок'];

    $pluralForm = function($n, $forms) {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return $forms[2];
        if ($n1 > 1 && $n1 < 5) return $forms[1];
        if ($n1 == 1) return $forms[0];
        return $forms[2];
    };

    $numToWords = function($n, $units) use ($hundreds, $tens, $teens) {
        if ($n == 0) return 'нуль';
        $result = '';
        if ($n >= 100) {
            $result .= $hundreds[floor($n / 100)] . ' ';
            $n %= 100;
        }
        if ($n >= 20) {
            $result .= $tens[floor($n / 10)] . ' ';
            $n %= 10;
        } elseif ($n >= 10) {
            $result .= $teens[$n - 10] . ' ';
            $n = 0;
        }
        if ($n > 0) {
            $result .= $units[$n] . ' ';
        }
        return trim($result);
    };

    $words = $numToWords($hryvnia, $unitsF);
    $words .= ' ' . $pluralForm($hryvnia, $hryvniaForms);
    if ($kopiyky > 0) {
        $words .= ' ' . $kopiyky . ' ' . $pluralForm($kopiyky, $kopiykyForms);
    }
    return $words;
}
}
