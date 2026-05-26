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

$code = trim($_GET['code'] ?? '');
$singleId = (int)($_GET['id'] ?? 0);

try {
    $api = new OpenCartDbClient();

    $source = $singleId ? 'id=' . $singleId : ($code ? 'code=' . $code : 'all');

    if ($code !== '') {
        $result = $api->syncProductByCode($pdo, $code);
    } elseif ($singleId) {
        $result = $api->syncProduct($pdo, $singleId);
    } else {
        $result = $api->syncProducts($pdo);
    }

    $status = ($result['error'] ?? null) ? 'warning' : 'success';
    $msg = ($result['error'] ?? null) ?: 'Синхронізовано ' . ($result['synced'] ?? 0) . ' товарів';
    logActivity($pdo, $status, $msg, 'sync-products/' . $source, $result);

    echo json_encode($result);
} catch (Exception $e) {
    $msg = 'Помилка синхронізації: ' . $e->getMessage();
    logActivity($pdo, 'error', $msg, 'sync-products', ['error' => $e->getMessage()]);
    echo json_encode(['error' => $msg]);
}
