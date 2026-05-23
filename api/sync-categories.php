<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../includes/opencart_api.php';

requireLogin();
header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['error' => 'Недостатньо прав']);
    exit;
}

try {
    $api = new OpenCartDbClient();
    $result = $api->syncCategories($pdo);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['error' => 'Помилка синхронізації категорій: ' . $e->getMessage()]);
}
