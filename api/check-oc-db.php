<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';

requireLogin();
header('Content-Type: text/plain; charset=utf-8');

if (!isAdmin()) {
    die('Недостатньо прав');
}

echo "OC_DB_HOST: " . OC_DB_HOST . "\n";
echo "OC_DB_NAME: " . OC_DB_NAME . "\n";
echo "OC_DB_USER: " . OC_DB_USER . "\n";
echo "OC_DB_PREFIX: " . OC_DB_PREFIX . "\n\n";

try {
    $pdo = new PDO(
        "mysql:host=" . OC_DB_HOST . ";dbname=" . OC_DB_NAME . ";charset=utf8mb4",
        OC_DB_USER,
        OC_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->query("SHOW TABLES LIKE '%product%'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "Таблиць з \"product\" не знайдено.\n\n";
        echo "Всі таблиці:\n";
        $all = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($all as $t) {
            echo "  $t\n";
        }
    } else {
        echo "Знайдені таблиці з \"product\":\n";
        foreach ($tables as $t) {
            echo "  $t\n";
        }
    }
} catch (Exception $e) {
    echo "Помилка: " . $e->getMessage() . "\n";
}
