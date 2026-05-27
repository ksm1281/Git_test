<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

try {
    $s = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT product_id, name, model, sku,
               price_retail, price_wholesale, price_semi_wholesale
        FROM erp_products
        WHERE status = 1
          AND (name LIKE ? OR model LIKE ? OR sku LIKE ?)
        ORDER BY name ASC
        LIMIT 20
    ");
    $stmt->execute([$s, $s, $s]);
    $rows = $stmt->fetchAll();
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
