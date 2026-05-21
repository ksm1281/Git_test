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

    echo "Таблиці з товарами:\n";
    $targets = ['tblProduct', 'tabTovar', 'tblPrice'];

    foreach ($targets as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->fetch();
        if ($exists) {
            $cnt = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "  ✅ $table ($cnt рядків)\n";
        } else {
            echo "  ❌ $table (не знайдено)\n";
        }
    }
} catch (Exception $e) {
    echo "Помилка: " . $e->getMessage() . "\n";
}
