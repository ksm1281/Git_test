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
        'draft' => 'bg-secondary',
        'confirmed' => 'bg-success',
        'cancelled' => 'bg-danger',
    ];
    $labels = [
        'pending' => 'Очікує',
        'approved' => 'Підтверджено',
        'processed' => 'В обробці',
        'shipped' => 'Відправлено',
        'delivered' => 'Доставлено',
        'draft' => 'Чернетка',
        'confirmed' => 'Підтверджено',
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
    $current = $pagination['current_page'];
    $total = $pagination['total_pages'];
    $range = 3;
    $html = '<nav><ul class="pagination justify-content-center flex-wrap">';
    $html .= '<li class="page-item ' . ($current <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&' . $pageParam . '=' . ($current - 1) . '">&laquo;</a></li>';
    $pages = [];
    $pages[] = 1;
    for ($i = max(2, $current - $range); $i <= min($total - 1, $current + $range); $i++) {
        $pages[] = $i;
    }
    if ($total > 1) $pages[] = $total;
    $pages = array_unique($pages);
    sort($pages);
    $last = 0;
    foreach ($pages as $p) {
        if ($p - $last > 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item ' . ($p === $current ? 'active' : '') . '">';
        $html .= '<a class="page-link" href="' . $baseUrl . '&' . $pageParam . '=' . $p . '">' . $p . '</a></li>';
        $last = $p;
    }
    $html .= '<li class="page-item ' . ($current >= $total ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&' . $pageParam . '=' . ($current + 1) . '">&raquo;</a></li>';
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

if (!function_exists('sendTelegramNotification')) {
function sendTelegramNotification($pdo, $message) {
    $stmt = $pdo->prepare("SELECT `key`, `value` FROM erp_settings WHERE `key` IN ('tg_bot_token', 'tg_chat_id')");
    $stmt->execute();
    $settings = [];
    foreach ($stmt as $row) {
        $settings[$row['key']] = $row['value'];
    }
    $token = $settings['tg_bot_token'] ?? '';
    $chatId = $settings['tg_chat_id'] ?? '';
    if (empty($token) || empty($chatId)) return false;

    $data = ['chat_id' => $chatId, 'text' => $message, 'parse_mode' => 'HTML'];
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode === 200;
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
