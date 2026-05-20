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

$singleId = (int)($_GET['id'] ?? 0);

try {
    $api = new OpenCartDbClient();

    if ($singleId) {
        $result = $api->syncProduct($pdo, $singleId);
    } else {
        $result = $api->syncProducts($pdo);
    }

    echo json_encode($result);
} catch (Exception $e) {
    $msg = 'Помилка синхронізації: ' . $e->getMessage();
    echo json_encode(['error' => $msg]);
}
