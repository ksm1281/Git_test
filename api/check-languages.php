<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';

requireLogin();
header('Content-Type: text/plain; charset=utf-8');

if (!isAdmin()) {
    die('Недостатньо прав');
}

try {
    $pdoOc = new PDO(
        "mysql:host=" . OC_DB_HOST . ";dbname=" . OC_DB_NAME . ";charset=utf8mb4",
        OC_DB_USER,
        OC_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    die('Помилка підключення до OC: ' . $e->getMessage() . "\n");
}

$prefix = OC_DB_PREFIX;

echo "=== Мови в OpenCart ===\n\n";
$stmt = $pdoOc->query("SELECT language_id, name, code, locale, status, sort_order FROM {$prefix}language ORDER BY sort_order");
$languages = $stmt->fetchAll();

if (empty($languages)) {
    echo "Мови не знайдені!\n";
} else {
    printf("%-5s %-20s %-10s %-15s %-7s %s\n", 'ID', 'Name', 'Code', 'Locale', 'Status', 'Order');
    echo str_repeat('-', 70) . "\n";
    foreach ($languages as $l) {
        printf("%-5d %-20s %-10s %-15s %-7d %d\n",
            $l['language_id'], $l['name'], $l['code'], $l['locale'], $l['status'], $l['sort_order']);
    }
}

echo "\n=== Вибір мови за пріоритетом ===\n";
$codes = ['uk', 'uk-ua', 'ru', 'en'];
foreach ($codes as $code) {
    $stmt = $pdoOc->prepare("SELECT language_id FROM {$prefix}language WHERE code = ?");
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    echo "  code='$code': " . ($id ? "language_id=$id" : "❌ не знайдено") . "\n";
}

$stmt = $pdoOc->query("SELECT MIN(language_id) FROM {$prefix}language");
$minId = $stmt->fetchColumn();
echo "  MIN(language_id): " . ($minId ? $minId : '❌') . "\n";

echo "\n=== Перевірка описів товарів ===\n";
$stmt = $pdoOc->query("SELECT pd.language_id, l.code, l.name, COUNT(*) as cnt FROM {$prefix}product_description pd LEFT JOIN {$prefix}language l ON pd.language_id = l.language_id GROUP BY pd.language_id ORDER BY pd.language_id");
$descs = $stmt->fetchAll();
if (empty($descs)) {
    echo "  Немає описів товарів\n";
} else {
    foreach ($descs as $d) {
        echo "  language_id={$d['language_id']} code={$d['code']} name={$d['name']}: {$d['cnt']} описів\n";
    }
}

echo "\n=== Перші 5 товарів (порівняння назв) ===\n";
$pidStmt = $pdoOc->query("SELECT DISTINCT product_id FROM {$prefix}product_description ORDER BY product_id LIMIT 5");
$pids = $pidStmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($pids)) {
    $placeholders = implode(',', $pids);
    $stmt = $pdoOc->query("
        SELECT pd.product_id, pd.language_id, l.code, l.name as lang_name, pd.name as product_name
        FROM {$prefix}product_description pd
        LEFT JOIN {$prefix}language l ON pd.language_id = l.language_id
        WHERE pd.product_id IN ($placeholders)
        ORDER BY pd.product_id, pd.language_id
    ");
    $current = null;
    while ($row = $stmt->fetch()) {
        if ($current !== $row['product_id']) {
            if ($current !== null) echo "\n";
            $current = $row['product_id'];
            echo "  Товар ID {$row['product_id']}:\n";
        }
        echo "    lang_id={$row['language_id']} ({$row['code']}): {$row['product_name']}\n";
    }
} else {
    echo "  Немає даних\n";
}

echo "\n=== Перші 5 категорій (порівняння назв) ===\n";
$cidStmt = $pdoOc->query("SELECT DISTINCT category_id FROM {$prefix}category_description ORDER BY category_id LIMIT 5");
$cids = $cidStmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($cids)) {
    $placeholders = implode(',', $cids);
    $stmt = $pdoOc->query("
        SELECT cd.category_id, cd.language_id, l.code, l.name as lang_name, cd.name as cat_name
        FROM {$prefix}category_description cd
        LEFT JOIN {$prefix}language l ON cd.language_id = l.language_id
        WHERE cd.category_id IN ($placeholders)
        ORDER BY cd.category_id, cd.language_id
    ");
    $current = null;
    while ($row = $stmt->fetch()) {
        if ($current !== $row['category_id']) {
            if ($current !== null) echo "\n";
            $current = $row['category_id'];
            echo "  Категорія ID {$row['category_id']}:\n";
        }
        echo "    lang_id={$row['language_id']} ({$row['code']}): {$row['cat_name']}\n";
    }
} else {
    echo "  Немає даних\n";
}

if (isset($_GET['pid'])) {
    $pid = (int)$_GET['pid'];
    echo "\n=== Товар ID $pid (всі мови) ===\n";
    $stmt = $pdoOc->prepare("
        SELECT pd.product_id, pd.language_id, l.code, l.name as lang_name, pd.name as product_name
        FROM {$prefix}product_description pd
        LEFT JOIN {$prefix}language l ON pd.language_id = l.language_id
        WHERE pd.product_id = ?
        ORDER BY pd.language_id
    ");
    $stmt->execute([$pid]);
    while ($row = $stmt->fetch()) {
        echo "  lang_id={$row['language_id']} ({$row['code']}): {$row['product_name']}\n";
    }
}

if (isset($_GET['cid'])) {
    $cid = (int)$_GET['cid'];
    echo "\n=== Категорія ID $cid (всі мови) ===\n";
    $stmt = $pdoOc->prepare("
        SELECT cd.category_id, cd.language_id, l.code, l.name as lang_name, cd.name as cat_name
        FROM {$prefix}category_description cd
        LEFT JOIN {$prefix}language l ON cd.language_id = l.language_id
        WHERE cd.category_id = ?
        ORDER BY cd.language_id
    ");
    $stmt->execute([$cid]);
    while ($row = $stmt->fetch()) {
        echo "  lang_id={$row['language_id']} ({$row['code']}): {$row['cat_name']}\n";
    }
}
